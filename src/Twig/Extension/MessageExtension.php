<?php

namespace Pixiekat\EmpathicaChatbot\Twig\Extension;

use Pixiekat\EmpathicaChatbot\Twig\Runtime\MessageExtensionRuntime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class MessageExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            // If your filter generates SAFE HTML, you should add a third
            // parameter: ['is_safe' => ['html']]
            // Reference: https://twig.symfony.com/doc/3.x/advanced.html#automatic-escaping
            // Prefixed because Twig filter names are global across the whole host
            // application, and a generic name like parse_md is exactly the kind
            // of thing another bundle (or Infosage itself) might also define.
            new TwigFilter('chatbot_markdown', [MessageExtensionRuntime::class, 'parseMarkdown']),
        ];
    }
}
