<?php

namespace Opifer\MediaBundle\Form\Type;

use Opifer\MediaBundle\Model\MediaManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MediaPickerType extends AbstractType
{
    /** @var MediaManagerInterface */
    private $mediaManager;

    public function __construct(MediaManagerInterface $mediaManager)
    {
        $this->mediaManager = $mediaManager;
    }

    /**
     * {@inheritdoc}
     */
    public function getParent()
    {
        return EntityType::class;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'class'        => $this->mediaManager->getClass(),
            'choice_label' => 'name',
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'mediapicker';
    }
}
