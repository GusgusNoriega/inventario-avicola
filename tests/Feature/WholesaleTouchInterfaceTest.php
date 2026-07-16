<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Tests\TestCase;

class WholesaleTouchInterfaceTest extends TestCase
{
    public function test_every_wholesale_text_and_number_field_exposes_a_touch_keyboard(): void
    {
        $html = $this->get('/operacion')
            ->assertOk()
            ->assertSee('<body class="operation-touch-page">', false)
            ->assertSee('id="textTouchKeyboard"', false)
            ->assertSee('id="numericPadModal"', false)
            ->assertSee('id="touchSelectModal"', false)
            ->getContent();

        $document = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($document);

        $textFields = [
            'clientSearch' => 'Buscar cliente o almacén',
            'providerSearch' => 'Buscar proveedor o almacén de origen',
            'deliveryTruckSearch' => 'Buscar camión de entrega',
            'deliveryDriverSearch' => 'Buscar chofer de entrega',
        ];

        foreach ($textFields as $id => $label) {
            $nodes = $xpath->query(sprintf('//input[@id="%s"]', $id));
            $this->assertCount(1, $nodes, "No se encontró el campo táctil {$id}.");

            $input = $nodes->item(0);
            $this->assertSame('search', $input->getAttribute('type'));
            $this->assertTrue($input->hasAttribute('readonly'));
            $this->assertSame('none', $input->getAttribute('inputmode'));
            $this->assertSame('text', $input->getAttribute('data-touch-keyboard'));
            $this->assertSame($label, $input->getAttribute('data-touch-keyboard-label'));
            $this->assertSame('120', $input->getAttribute('maxlength'));
        }

        $numericInputs = $xpath->query('//input[translate(@type, "NUMBER", "number") = "number"]');
        $this->assertCount(8, $numericInputs);

        foreach ($numericInputs as $input) {
            $this->assertTrue($input->hasAttribute('readonly'), "{$input->getAttribute('id')} debe ser readonly.");
            $this->assertSame('none', $input->getAttribute('inputmode'));
            $this->assertTrue($input->hasAttribute('data-keypad-label'));
        }

        $this->assertGreaterThanOrEqual(8, $xpath->query('//select')->length);
        $this->assertCount(1, $xpath->query('//*[@data-text-keyboard-action="accept"]'));
        $this->assertCount(1, $xpath->query('//*[@data-text-keyboard-action="cancel"]'));
        $this->assertCount(1, $xpath->query('//*[@data-text-keyboard-action="backspace"]'));
        $this->assertCount(1, $xpath->query('//*[@data-text-keyboard-action="clear"]'));
    }

    public function test_touch_keyboard_logic_filters_live_and_preserves_existing_business_events(): void
    {
        $javascript = file_get_contents(public_path('js/app.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('function openTextTouchKeyboard(input)', $javascript);
        $this->assertStringContainsString('function closeTextTouchKeyboard(commit = true, restoreFocus = true)', $javascript);
        $this->assertStringContainsString('function bindTextTouchInputs()', $javascript);
        $this->assertStringContainsString('["A", "S", "D", "F", "G", "H", "J", "K", "L", "Ñ"]', $javascript);
        $this->assertStringContainsString('["Á", "É", "Í", "Ó", "Ú", "Ü", "-", "/", "."]', $javascript);
        $this->assertStringContainsString('data-text-keyboard-key=" ">Espacio', $javascript);
        $this->assertStringContainsString('target.dispatchEvent(new Event("input", { bubbles: true }))', $javascript);
        $this->assertStringContainsString('target.dispatchEvent(new Event("change", { bubbles: true }))', $javascript);
        $this->assertStringContainsString('textKeyboardContext.initialValue', $javascript);
        $this->assertStringContainsString('if (elements.textTouchKeyboard && !elements.textTouchKeyboard.hidden)', $javascript);

        $this->assertStringContainsString('keypadContext.replaceOnNextKey = true;', $javascript);
        $this->assertStringContainsString('acceptedKey = key.slice(0, remaining);', $javascript);
        $this->assertStringContainsString('keypadContext.decimalPlaces = getInputDecimalPlaces(input);', $javascript);

        $this->assertStringContainsString('select.dispatchEvent(new Event("input", { bubbles: true }))', $javascript);
        $this->assertStringContainsString('select.dispatchEvent(new Event("change", { bubbles: true }))', $javascript);
        $this->assertStringContainsString('select.setAttribute("aria-controls", "touchSelectModal")', $javascript);
        $this->assertStringContainsString('select.setAttribute("aria-expanded", "true")', $javascript);
        $this->assertStringContainsString('suppressNextClick = tapState.moved || holdMs > maxHoldMs;', $javascript);

        $touchSelectBindingStart = strpos($javascript, 'function bindTouchSelects()');
        $touchSelectBindingEnd = strpos($javascript, 'function calculateTruckTotals', $touchSelectBindingStart);
        $this->assertNotFalse($touchSelectBindingStart);
        $this->assertNotFalse($touchSelectBindingEnd);
        $touchSelectBinding = substr($javascript, $touchSelectBindingStart, $touchSelectBindingEnd - $touchSelectBindingStart);
        $this->assertStringContainsString('select.addEventListener("click"', $touchSelectBinding);
        $this->assertStringContainsString('openTouchSelect(select);', $touchSelectBinding);

        $errorModalStart = strpos($javascript, 'function showErrorModal');
        $errorModalEnd = strpos($javascript, 'function closeErrorModal', $errorModalStart);
        $this->assertNotFalse($errorModalStart);
        $this->assertNotFalse($errorModalEnd);
        $errorModalFlow = substr($javascript, $errorModalStart, $errorModalEnd - $errorModalStart);
        $this->assertStringContainsString('closeTextTouchKeyboard(true, false);', $errorModalFlow);
        $this->assertStringContainsString('closeNumericPad();', $errorModalFlow);
        $this->assertStringContainsString('closeTouchSelect();', $errorModalFlow);
        $this->assertStringContainsString('elements.closeErrorModalBtn?.focus', $errorModalFlow);

        $this->assertSame(1, substr_count($javascript, 'elements.scaleSetButtons[1].addEventListener'));
        $this->assertSame(1, substr_count($javascript, 'elements.scaleSetButtons[2].addEventListener'));
        $this->assertStringContainsString('bindTextTouchInputs();', $javascript);
    }

    public function test_touch_keyboards_are_responsive_and_layer_above_parent_modals(): void
    {
        $stylesheet = file_get_contents(public_path('css/style.css'));

        $this->assertIsString($stylesheet);
        $this->assertMatchesRegularExpression('/\.text-touch-keyboard\s*\{[^}]*z-index:\s*280;/s', $stylesheet);
        $this->assertMatchesRegularExpression('/\.error-modal\s*\{[^}]*z-index:\s*300;/s', $stylesheet);
        $this->assertStringContainsString('pointer-events: none;', $stylesheet);
        $this->assertStringContainsString('.text-touch-keyboard-card', $stylesheet);
        $this->assertStringContainsString('max-height: calc(100dvh - 16px);', $stylesheet);
        $this->assertStringContainsString('@media (max-width: 640px)', $stylesheet);
        $this->assertStringContainsString('@media (max-height: 650px)', $stylesheet);
        $this->assertStringContainsString('.touch-text-control', $stylesheet);
        $this->assertStringContainsString('.operation-touch-page .font-size-stepper button', $stylesheet);
        $this->assertStringContainsString('.operation-touch-page .entry-section .weighing-submit-button', $stylesheet);
        $this->assertStringContainsString('grid-template-rows: auto minmax(220px, 1fr) auto;', $stylesheet);
        $this->assertStringContainsString('touch-action: manipulation;', $stylesheet);
    }
}
