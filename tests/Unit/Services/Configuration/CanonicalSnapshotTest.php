<?php

namespace Tests\Unit\Services\Configuration;

use App\Services\Configuration\CanonicalSnapshot;
use PHPUnit\Framework\TestCase;

class CanonicalSnapshotTest extends TestCase
{
    public function test_encoding_matches_the_node_canonical_json_contract(): void
    {
        $snapshot = [
            'text' => 'café/東京 <&>',
            'items' => [
                3,
                ['z' => 'é', 'a' => 'x/y'],
            ],
        ];

        $this->assertSame(
            '{"items":[3,{"a":"x/y","z":"é"}],"text":"café/東京 <&>"}',
            (new CanonicalSnapshot())->encode($snapshot)
        );
    }

    public function test_encoding_preserves_list_order_while_sorting_nested_object_keys(): void
    {
        $snapshot = [
            'z' => 1,
            'a' => ['y' => 2, 'b' => 3],
            'items' => [['z' => 1, 'a' => 2], 4],
        ];

        $this->assertSame(
            '{"a":{"b":3,"y":2},"items":[{"a":2,"z":1},4],"z":1}',
            (new CanonicalSnapshot())->encode($snapshot)
        );
    }
}
