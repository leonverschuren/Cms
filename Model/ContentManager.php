<?php

namespace Opifer\ContentBundle\Model;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

use Opifer\CrudBundle\Pagination\Paginator;
use Opifer\EavBundle\Form\Type\NestedContentType;
use Opifer\EavBundle\Manager\EavManager;
use Opifer\EavBundle\Entity\NestedValue;

class ContentManager implements ContentManagerInterface
{
    /** @var EntityManager */
    protected $em;

    /** @var FormFactoryInterface */
    protected $formFactory;

    /** @var EavManager */
    protected $eavManager;

    /** @var string */
    protected $class;

    /** @var string */
    protected $templateClass;

    /**
     * Constructor
     *
     * @param EntityManagerInterface $em
     * @param FormFactoryInterface   $formFactory
     * @param EavManager             $eavManager
     */
    public function __construct(EntityManagerInterface $em, FormFactoryInterface $formFactory, EavManager $eavManager, $class, $templateClass)
    {
        if (!is_subclass_of($class, 'Opifer\ContentBundle\Model\ContentInterface')) {
            throw new \Exception($class .' must implement Opifer\ContentBundle\Model\ContentInterface');
        }

        $this->em = $em;
        $this->formFactory = $formFactory;
        $this->eavManager = $eavManager;
        $this->class = $class;
        $this->templateClass = $templateClass;
    }

    /**
     * Get the class
     *
     * @return string
     */
    public function getClass()
    {
        return $this->class;
    }

    /**
     * Get repository
     *
     * @return \Doctrine\ORM\EntityRepository
     */
    public function getRepository()
    {
        return $this->em->getRepository($this->getClass());
    }

    /**
     * {@inheritDoc}
     */
    public function getPaginatedByRequest(Request $request)
    {
        $qb = $this->getRepository()->getQueryBuilderFromRequest($request);

        $page = ($request->get('p')) ? $request->get('p') : 1;
        $limit = ($request->get('limit')) ? $request->get('limit') : 25;

        return new Paginator($qb, $limit, $page);
    }

    /**
     * {@inheritDoc}
     */
    public function findOneBySlug($slug)
    {
        return $this->getRepository()->findOneBySlug($slug);
    }
    
    /**
     * Find published content
     *
     * @param string $slug
     *
     * @return ContentInterface
     */
    public function findActiveBySlug($slug)
    {
        return $this->getRepository()->findActiveBySlug($slug);
    }
    
    /**
     * Find published content by alias
     *
     * @param string $alias
     *
     * @return ContentInterface
     */
    public function findActiveByAlias($alias)
    {
        return $this->getRepository()->findActiveByAlias($alias);
    }

    /**
     * Handle the nested content forms
     *
     * @param ContentInterface $content
     * @param Request $request
     *
     * @throws \Exception
     */
    public function handleNestedContentForm(ContentInterface $content, Request $request)
    {
        $this->recursiveContentMapper($content, $request);
    }

    /**
     * Maps the formdata to the related nestedcontent item resursively
     *
     * @param ContentInterface $content
     * @param $request
     * @param int $level
     * @param null $parent
     *
     * @return bool
     *
     * @throws \Exception
     */
    public function recursiveContentMapper(ContentInterface $content, $request, $level = 1, $parent = null)
    {
        $formdata = $request->request->all();

        $relatedformdata = $this->getFormDataByLevel($formdata, $level, $parent);

        foreach ($content->getNestedContentAttributes() as $attribute => $value) {

            $oldIds = explode(',', $formdata['eav_nested_content_value_'.$attribute]);
            $ids = [];

            $sort = 0;
            foreach ($relatedformdata as $key => $data) {
                $keys = $this->separateKeys($key);

                $nestedContent = $this->getContentByReference($keys['reference']);

                $form = new NestedContentType($keys['attribute'], $key);
                $form = $this->formFactory->create($form, $nestedContent);
                $form->handleRequest($request);

                $nestedContent->setNestedSort($sort);
                $sort++;

                // We do not check the standard isValid() method here, because our form
                // is not actually submitted.
                if (count($form->getErrors(true)) < 1) {
                    $this->em->persist($value);
                    $value->addNested($nestedContent);
                    $nestedContent->setNestedIn($value);
                    $this->save($nestedContent);

                    $ids[] = $nestedContent->getId();
                } else {
                    throw new \Exception('Something went wrong while saving nested content. Message: '. $form->getErrors());
                }

                $level++;

                $this->recursiveContentMapper($nestedContent, $request, $level, $nestedContent->getId());
            }

            $this->remove(array_diff($oldIds, $ids));
        }

        return true;
    }

    /**
     * Get the content by a reference
     *
     * If the passed reference is a numeric, it must be the content ID from a
     * to-be-updated content item.
     * If not, the reference must be the template name for a to-be-created
     * content item.
     *
     * @param int|string $reference
     *
     * @return \Opifer\ContentBundle\Model\ContentInterface
     */
    public function getContentByReference($reference)
    {
        if (is_numeric($reference)) {
            // If the reference is numeric, it must be the content ID from an existing
            // content item, which has to be updated.
            $nestedContent = $this->getRepository()->find($reference);
        } else {
            // If not, $reference is a template name for a to-be-created content item.
            $template = $this->em->getRepository($this->templateClass)->findOneByName($reference);

            $nestedContent = $this->eavManager->initializeEntity($template);
            $nestedContent->setNestedDefaults();
        }

        return $nestedContent;
    }

    /**
     * Get the formdata related to the level and optionally the parent
     *
     * @param array $formdata
     * @param int $level
     * @param null $parent
     *
     * @return array
     */
    private function getFormDataByLevel($formdata, $level = 1, $parent = null)
    {
        $collection = [];
        foreach ($formdata as $key => $data) {
            if (!strpos($key, NestedContentType::NAME_SEPARATOR)) {
                continue;
            }

            $keys = explode(NestedContentType::NAME_SEPARATOR, $key);
            array_shift($keys);

            if (count($keys) == ($level * 3)) {
                if (($parent && ($parent == $keys[count($keys) - 5])) || !$parent) {
                    $collection[$key] = $data;
                }
            }
        }

        return $collection;
    }

    /**
     * Parses the keys string to a usable array
     *
     * @param $key
     *
     * @return array
     */
    private function separateKeys($key)
    {
        $keys = explode(NestedContentType::NAME_SEPARATOR, $key);

        return [
            'index' => end($keys),
            'reference' => prev($keys),
            'attribute' => prev($keys)
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function save(ContentInterface $content)
    {
        if (!$content->getId()) {
            $this->em->persist($content);
        }

        $this->em->flush();

        return $content;
    }

    /**
     * {@inheritDoc}
     */
    public function remove($content)
    {
        if (!is_array($content)) {
            $content = [$content];
        }

        $content = $this->getRepository()->findByIds($content);
        foreach ($content as $item) {
            $this->em->remove($item);
        }

        $this->em->flush();
    }

    /**
     * @param ContentInterface $content
     * @param NestedValue $nestedIn
     */
    public function duplicate(ContentInterface $content, NestedValue $nestedIn = null)
    {
        //get valueset to clone
        $valueset = $content->getValueSet();

        //clone valueset
        $duplicatedValueset = clone $valueset;

        $this->detachAndPersist($duplicatedValueset);

        //duplicate content
        $duplicatedContent = clone $content;
        $duplicatedContent->setValueSet($duplicatedValueset);

        if (!is_null($nestedIn)) {
            $duplicatedContent->setNestedIn($nestedIn);
        }

        $this->detachAndPersist($duplicatedContent);

        //iterate values, clone each and assign duplicate valueset to it
        foreach ($valueset->getValues() as $value) {

            //skip empty attributes
            if (is_null($value->getId())) continue;

            $duplicatedValue = clone ($value);
            $duplicatedValue->setValueSet($duplicatedValueset);

            $this->detachAndPersist($duplicatedValue);

            //if type nested, find content that has nested_in value same as id of value
            if ($value instanceof \Opifer\EavBundle\Entity\NestedValue) {
                $nestedContents = $this->getRepository()->findby(['nestedIn' => $value->getId()]);

                foreach ($nestedContents as $nestedContent) {
                    $this->duplicate($nestedContent, $duplicatedValue);
                }
            }
        }
        $this->em->flush();

        return $duplicatedContent->getId();
    }

    /**
     * For cloning purpose
     * @param \Opifer\ContentBundle\Model\ContentInterface|\Opifer\EavBundle\Model\ValueSetInterface|\Opifer\EavBundle\Entity\Value $entity
     */
    private function detachAndPersist($entity)
    {
        $this->em->detach($entity);
        $this->em->persist($entity);
    }
}
