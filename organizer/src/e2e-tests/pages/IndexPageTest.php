<?php

require_once __DIR__ . '/common/E2EPageTestCase.php';

class IndexPageTest extends E2EPageTestCase {

    public function testPageLoggedIn() {
        $response = $this->renderPage('/');

        // :: Assert basic page content - the heading
        $this->assertStringContainsString(
            '<h1>Offpost - Email Engine Organizer</h1>', $response->body);
        

        // :: Assert that page rendered data
        $this->assertStringContainsString('Innsyn valggjennomf&oslash;ring, Nord-Odal kommune', $response->body);
        $this->assertStringContainsString('Valgstyrets_m&oslash;tebok_kommunevalg2023.pdf', $response->body);
    }

}