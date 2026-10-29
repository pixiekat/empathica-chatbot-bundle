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
  /**
   * The longest message, in characters, that one turn may carry.
   *
   * Every message is sent to a paid provider and then replayed as history on
   * later turns, so an unbounded box is an unbounded bill: one pasted
   * document would be charged again on every message after it. 4,000
   * characters is roughly 1,000 tokens, which is several long paragraphs and
   * far more than a question about a medication needs.
   *
   * Enforced twice, on purpose:
   *   - the Length constraint is the real rule, because a request can be
   *     crafted without ever touching this form;
   *   - the maxlength attribute stops the browser accepting more, so the
   *     limit is met while typing rather than discovered after a round trip.
   * The help text states the number, because maxlength on its own just stops
   * accepting keystrokes without saying why.
   */
  public const MESSAGE_MAX_LENGTH = 4000;

  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder
      ->add('message', FormTypes\TextareaType::class, [
        'constraints' => [
          // Named argument rather than an options array: the array form
          // was removed in Symfony 8. See PreferencesType for the note.
          new Assert\NotBlank(message: 'Please enter a message.'),
          new Assert\Length(
            max: self::MESSAGE_MAX_LENGTH,
            maxMessage: 'Please keep your message to {{ limit }} characters or fewer.',
          ),
        ],
        'attr' => [
          'placeholder' => "",
          'rows' => 8,
          'style' => 'width: 100%',
          'maxlength' => self::MESSAGE_MAX_LENGTH,
        ],
        // Help text rather than a separate notice: Symfony links it to the
        // box with aria-describedby, so a screen reader reads the privacy
        // sentence along with the field, exactly when someone is about to
        // type. A notice elsewhere on the page is easy to never reach.
        'help' => sprintf(
          'Up to %s characters. Conversations are saved, and the research team can read them.',
          number_format(self::MESSAGE_MAX_LENGTH),
        ),
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
