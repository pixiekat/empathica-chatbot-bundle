<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type as FormTypes;
use Symfony\Component\Validator\Constraints as Assert;

class ChatbotInputType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder
      ->add('message', FormTypes\TextareaType::class, [
        'constraints' => [
          // Named argument rather than an options array: the array form
          // was removed in Symfony 8. See PreferencesType for the note.
          new Assert\NotBlank(message: 'Please enter a message.'),
        ],
        'attr' => [
          'placeholder' => "",
          'rows' => 8,
          'style' => 'width: 100%',
        ],
        //'label' => 'Message Chatbot',
        'required' => true,
      ])
      ->add('submit', FormTypes\SubmitType::class, [
        'label' => 'Submit',
      ])
    ;
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      // Configure your form options here
    ]);
  }
}
