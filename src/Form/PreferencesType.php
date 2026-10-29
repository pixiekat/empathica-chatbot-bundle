<?php
declare(strict_types=1);
namespace Pixiekat\EmpathicaChatbot\Form;

use Pixiekat\EmpathicaChatbot\Entity;
use Pixiekat\EmpathicaChatbot\Interfaces;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type as FormTypes;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The chatbot settings page, bound to ChatbotPreferences.
 *
 * Theme and larger-font toggles were removed when this moved into a bundle:
 * those are site-wide accessibility settings owned by the host application,
 * and a second copy here could only ever disagree with the first.
 */
class PreferencesType extends AbstractType
{

  /**
   * @param list<string> $selectableProviders
   *   `empathica_chatbot.selectable_providers` — which registered providers an
   *   admin may pick from. Configuration rather than a hardcoded list so a
   *   deployment can offer a provider once it has a key for it, without a code
   *   change.
   */
  public function __construct(
    private readonly Security $security,
    private readonly array $selectableProviders = [],
  ) {
  }

  /**
   * Whether the research-only controls should be offered.
   *
   * Asked of the voter rather than checked as a role here, so the role name
   * stays in one place (empathica_chatbot.admin_role).
   */
  private function canChangeResearchSettings(): bool
  {
    return $this->security->isGranted(Interfaces\Security\Voter\DefaultVoterInterface::CAN_CHANGE_RESEARCH_SETTINGS);
  }

  /**
   * NOTE ON CONSTRAINT SYNTAX: constraints use named arguments
   * (new Assert\Choice(choices: [...])), not the older options array
   * (new Assert\Choice(['choices' => [...]])). The array form was deprecated
   * in Symfony 7.3 and removed in 8.0, so it would stop this form working the
   * moment the host upgraded.
   */
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $isResearcher = $this->canChangeResearchSettings();

    $choices = [
      'Low empathy (focus on factual information without emotional language)' => 1,
      'Medium empathy (provide some emotional support and reassurance)' => 2,
      'High empathy (prioritize emotional support and reassurance)' => 3,
    ];

    // Levels 4 and 5 are testing-only arms and must never be offered to a
    // participant. The Choice constraint below still lists them, so a
    // researcher's saved value validates; a participant cannot select them
    // because they are not rendered as options at all.
    if ($isResearcher) {
      $choices['Anti empathy (focus solely on medical information without acknowledging user emotions)'] = 4;
      $choices['Confrontational (challenge user assumptions or beliefs about medications or conditions)'] = 5;
    }

    $builder
      ->add('memory', FormTypes\TextareaType::class, [
        // The privacy sentence is part of the help text, not a separate
        // notice, so it is read out with the field (aria-describedby) at the
        // moment someone is deciding what to type into it.
        'help' => sprintf(
          'Provide any relevant medical history or context that the chatbot should consider when responding to your queries. This can include information about your current medications, medical conditions, allergies, or any other details you think are important for the chatbot to know in order to provide accurate and personalized responses. This will be sent with every query to the chatbot and can be updated at any time. It is stored with your conversations, which the research team can read. Up to %s characters.',
          number_format(Entity\ChatbotPreferences::MEMORY_MAX_LENGTH),
        ),
        'label' => 'Additional Saved Medical Context',
        'required' => false,
        'constraints' => [
          // See ChatbotPreferences::MEMORY_MAX_LENGTH for why this is capped.
          new Assert\Length(
            max: Entity\ChatbotPreferences::MEMORY_MAX_LENGTH,
            maxMessage: 'Please keep your saved context to {{ limit }} characters or fewer.',
          ),
        ],
        'attr' => [
          'rows' => 10,
          'maxlength' => Entity\ChatbotPreferences::MEMORY_MAX_LENGTH,
        ]
      ])
      ->add('literacyLevel', FormTypes\ChoiceType::class, [
        'constraints' => [
          new Assert\NotBlank(),
          new Assert\Choice(choices: [1, 2, 3, 4]),
        ],
        'choices' => [
          'Use basic language (suitable for users with low health literacy)' => 1,
          'Use intermediate language (suitable for users with average health literacy)' => 2,
          'Use advanced or clinical language (suitable for users with high health literacy)' => 3,
          'Clinical language (use medical terminology and detailed explanations, suitable for healthcare professionals)' => 4,
        ],
        'label' => 'Literacy Level',
        'required' => true,
      ])
      ->add('empathyLevel', FormTypes\ChoiceType::class, [
        'constraints' => [
          new Assert\NotBlank(),
          new Assert\Choice(choices: [1, 2, 3, 4, 5]),
        ],
        'choices' => $choices,
        'label' => 'Empathy Level',
        'required' => true,
      ])
    ;

    if ($isResearcher) {
      $personas = [
        Interfaces\Services\ChatbotManagerInterface::PERSONA_EMILY,
        Interfaces\Services\ChatbotManagerInterface::PERSONA_MARY,
      ];

      $builder
        ->add('persona', FormTypes\ChoiceType::class, [
          'constraints' => [
            new Assert\NotBlank(),
            new Assert\Choice(choices: $personas),
          ],
          // array_combine: label and value are the same string.
          'choices' => array_combine($personas, $personas),
          'label' => 'Persona',
          'required' => true,
        ])
        ->add('provider', FormTypes\ChoiceType::class, [
          'constraints' => [
            new Assert\NotBlank(),
            new Assert\Choice(choices: $this->selectableProviders),
          ],
          'choices' => array_combine($this->selectableProviders, $this->selectableProviders),
          'label' => 'Chatbot',
          'required' => true,
        ])
        ->add('debugModeEnabled', FormTypes\CheckboxType::class, [
          'help' => 'Enable debug mode to see additional information in the chatbot responses for troubleshooting purposes. Never enable this for a study participant: the model will announce which empathy and literacy levels are active.',
          'label' => 'Debug Mode',
          'required' => false,
        ])
      ;
    }

    $builder
      ->add('submit', FormTypes\SubmitType::class, [
        'label' => 'Save Preferences',
      ])
    ;
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'data_class' => Entity\ChatbotPreferences::class,
    ]);
  }
}
