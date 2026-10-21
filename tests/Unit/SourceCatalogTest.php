<?php

namespace Tests\Unit;

use App\Services\Mindmap\SourceCatalog;
use PHPUnit\Framework\TestCase;

class SourceCatalogTest extends TestCase
{
    public function test_catalog_exposes_six_levels_and_known_source_roles(): void
    {
        $catalog = new SourceCatalog();

        $this->assertCount(6, SourceCatalog::LEVELS);
        $this->assertSame('Date de bază', SourceCatalog::LEVELS[1]['name']);
        $this->assertSame('Inteligență predictivă', SourceCatalog::LEVELS[6]['name']);
        $this->assertSame(14, count($catalog->entries()));
        $this->assertSame('Competiții, sezoane, program și rezultate furnizate de football-data.org.', SourceCatalog::roleFor('football-data', 1));
        $this->assertStringContainsString('Contribuție secundară', SourceCatalog::roleFor('unknown-source', 1));
        $this->assertTrue(SourceCatalog::levelApplies('football-data-uk', 2));
        $this->assertFalse(SourceCatalog::levelApplies('football-data-uk', 6));
        $this->assertTrue(SourceCatalog::levelApplies('unknown-source', 6));
        $this->assertSame('REST API · JSON', SourceCatalog::TYPES['rest']);
        $this->assertSame('Fotbal · API-uri', SourceCatalog::GROUPS['football']);
    }
}
