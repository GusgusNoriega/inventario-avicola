<?php

namespace Tests\Feature;

use Tests\TestCase;

class RetailClientKeyboardLayoutTest extends TestCase
{
    public function test_client_search_uses_a_letters_only_keyboard_layout(): void
    {
        $blade = (string) file_get_contents(resource_path('views/despacho-minorista.blade.php'));
        $javascript = (string) file_get_contents(public_path('js/despacho-minorista.js'));

        $this->assertStringContainsString('data-retail-keyboard-layout="letters"', $blade);
        $this->assertStringContainsString('<span>Buscar por nombre</span>', $blade);
        $this->assertStringNotContainsString('Buscar por nombre o documento', $blade);
        $this->assertStringContainsString('const lettersOnly = touchKeyboardState.layout === "letters";', $javascript);
        $this->assertStringContainsString('lettersOnly ? "Teclado táctil de letras"', $javascript);
        $this->assertStringContainsString(
            'elements.touchKeyboard.classList.toggle("is-client-search", isClientSearch);',
            $javascript,
        );

        $matched = preg_match(
            '~const rows = lettersOnly\s*\?\s*(\[[\s\S]*?\n    \])\s*:\s*\[~',
            $javascript,
            $letterLayout,
        );

        $this->assertSame(1, $matched);
        $this->assertStringContainsString('["Q", "W", "E", "R", "T", "Y", "U", "I", "O", "P"]', $letterLayout[1]);
        $this->assertStringContainsString('["A", "S", "D", "F", "G", "H", "J", "K", "L", "Ñ"]', $letterLayout[1]);
        $this->assertStringContainsString('["Á", "É", "Í", "Ó", "Ú", "Ü"]', $letterLayout[1]);

        foreach (['"1"', '"0"', '"-"', '"/"', '"."'] as $forbiddenKey) {
            $this->assertStringNotContainsString($forbiddenKey, $letterLayout[1]);
        }
    }

    public function test_client_modal_and_keyboard_use_separate_responsive_regions(): void
    {
        $stylesheet = (string) file_get_contents(public_path('css/despacho-minorista.css'));

        $this->assertStringContainsString('#retailClientModal', $stylesheet);
        $this->assertStringContainsString('body.has-retail-client-keyboard .rd-modal-card.is-client', $stylesheet);
        $this->assertStringContainsString('.rd-touch-keyboard.is-client-search', $stylesheet);
        $this->assertStringContainsString('top: var(--rd-client-keyboard-start);', $stylesheet);
        $this->assertStringContainsString('@media (max-height: 680px)', $stylesheet);
    }

    public function test_client_search_letters_keep_a_large_minimum_size_on_all_viewports(): void
    {
        $stylesheet = (string) file_get_contents(public_path('css/despacho-minorista.css'));

        $variableMatched = preg_match(
            '~--rd-client-search-key-font:\s*clamp\((\d+)px,\s*calc\(var\(--rd-font-modal-button\)\s*\*\s*([0-9.]+)\),\s*(\d+)px\);~',
            $stylesheet,
            $fontSize,
        );

        $this->assertSame(1, $variableMatched, 'No se encontró el tamaño exclusivo de las letras del buscador.');
        $this->assertGreaterThanOrEqual(32, (int) $fontSize[1]);
        $this->assertGreaterThan(1.75, (float) $fontSize[2]);
        $this->assertGreaterThan((int) $fontSize[1], (int) $fontSize[3]);

        $selectorMatched = preg_match(
            '~\.rd-touch-keyboard\.is-client-search\s+\.rd-touch-keyboard-keys\.is-letters-only\s+button\[data-retail-keyboard-key\]:not\(\.is-space\)\s*\{([^}]*)\}~s',
            $stylesheet,
            $rules,
        );

        $this->assertSame(1, $selectorMatched, 'Las letras del buscador no tienen una regla CSS específica.');
        $this->assertStringContainsString('font-size: var(--rd-client-search-key-font);', $rules[1]);
        $this->assertStringContainsString('line-height: 1;', $rules[1]);

        $mobileRulePosition = strpos($stylesheet, '@media (max-width: 720px)');
        $clientRulePosition = strpos($stylesheet, $rules[0]);

        $this->assertNotFalse($mobileRulePosition);
        $this->assertNotFalse($clientRulePosition);
        $this->assertGreaterThan($mobileRulePosition, $clientRulePosition);
    }
}
