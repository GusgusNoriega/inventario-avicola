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

    public function test_touch_keyboard_uses_large_labels_on_desktop_and_mobile(): void
    {
        $stylesheet = (string) file_get_contents(public_path('css/despacho-minorista.css'));

        $this->assertStringContainsString(
            'font-size: calc(var(--rd-font-modal-button) * 1.75);',
            $stylesheet,
        );
        $this->assertStringContainsString(
            'font-size: calc(var(--rd-font-modal-button) * 2.5);',
            $stylesheet,
        );
        $this->assertStringContainsString(
            'font-size: calc(var(--rd-font-modal-button) * 1.42);',
            $stylesheet,
        );
    }
}
