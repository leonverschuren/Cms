<?php

namespace Opifer\CmsBundle\Controller\Backend;

use APY\DataGridBundle\Grid\Action\RowAction;
use APY\DataGridBundle\Grid\Column\TextColumn;
use APY\DataGridBundle\Grid\Source\Entity;
use Doctrine\Common\Collections\ArrayCollection;
use Opifer\CmsBundle\Entity\Template;
use Opifer\EavBundle\Form\Type\TemplateType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TemplateController extends Controller
{
    /**
     * @return Response
     */
    public function indexAction()
    {
        $source = new Entity('OpiferCmsBundle:Template');

        $editAction = new RowAction('edit', 'opifer_cms_template_edit');
        $editAction->setRouteParameters(['id']);

        $deleteAction = new RowAction('delete', 'opifer_cms_template_delete');
        $deleteAction->setRouteParameters(['id']);

        $attributesColumn = new TextColumn(['id' => 'attributes', 'Attributes', 'source' => false, 'filterable' => false, 'sortable' => false, 'safe' => false]);
        $attributesColumn->manipulateRenderCell(function ($value, $row, $router) {
            $html = '';
            foreach ($row->getEntity()->getAttributes() as $attribute) {
                $html .= '<span class="label label-primary" style="display:inline-block">'.$attribute->getDisplayName().'</span>';
            }

            return $html;
        });

        $grid = $this->get('grid');
        $grid->setId('templates')
            ->setSource($source)
            ->addColumn($attributesColumn)
            ->addRowAction($editAction)
            ->addRowAction($deleteAction);

        return $grid->getGridResponse('OpiferCmsBundle:Backend/Template:index.html.twig');
    }

    /**
     * @param Request $request
     *
     * @return RedirectResponse|Response
     */
    public function createAction(Request $request)
    {
        $template = new Template();

        $form = $this->createForm(TemplateType::class, $template);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();

            foreach ($form->getData()->getAttributes() as $attribute) {
                $attribute->setTemplate($template);

                foreach ($attribute->getOptions() as $option) {
                    $option->setAttribute($attribute);
                }
            }

            $em->persist($template);
            $em->flush();

            return $this->redirectToRoute('opifer_cms_template_edit', ['id' => $template->getId()]);
        }

        return $this->render('OpiferCmsBundle:Backend/Template:create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * @param Request $request
     * @param int     $id
     *
     * @return RedirectResponse|Response
     */
    public function editAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();
        $template = $em->getRepository('OpiferCmsBundle:Template')->find($id);

        $form = $this->createForm(TemplateType::class, $template);
        $form->handleRequest($request);

        $originalAttributes = new ArrayCollection();
        foreach ($template->getAttributes() as $attributes) {
            $originalAttributes->add($attributes);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            // Delete removed attributes
            foreach ($originalAttributes as $attribute) {
                if (false === $form->getData()->getAttributes()->contains($attribute)) {
                    $em->remove($attribute);
                }
            }

            // Add new attributes
            foreach ($form->getData()->getAttributes() as $attribute) {
                $attribute->setTemplate($template);

                foreach ($attribute->getOptions() as $option) {
                    $option->setAttribute($attribute);
                }
            }

            $em->flush();

            return $this->redirectToRoute('opifer_cms_template_edit', ['id' => $template->getId()]);
        }

        return $this->render('OpiferCmsBundle:Backend/Template:edit.html.twig', [
            'form' => $form->createView(),
            'template' => $template,
        ]);
    }

    /**
     * @param int $id
     *
     * @return RedirectResponse
     */
    public function deleteAction($id)
    {
        $em = $this->getDoctrine()->getManager();
        $template = $this->get('opifer.eav.template_manager')->getRepository()->find($id);

        if (!$template) {
            return $this->createNotFoundException();
        }

        $relatedContent = $em->getRepository('OpiferCmsBundle:Content')
            ->createValuedQueryBuilder('c')
            ->innerJoin('vs.template', 't')
            ->select('COUNT(c)')
            ->where('t.id = :template')
            ->setParameter('template', $id)
            ->getQuery()
            ->getSingleScalarResult();

        if ($relatedContent > 0) {
            $this->addFlash('error', 'template.delete.warning');

            return $this->redirectToRoute('opifer_cms_template_index');
        }

        $em->remove($template);
        $em->flush();

        return $this->redirectToRoute('opifer_cms_template_index');
    }
}
