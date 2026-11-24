<?php

/** Tests for the FIDES architecture check. Each test builds a small src/ tree
 *  in a temporary folder and is named for the rule it checks.
 *  Run: php fides/tests/run.php  (no Composer packages needed) */

require_once dirname(__DIR__).'/arch-check.php';

const WING = 'src/Entities/Wing/Wing.php';

/** Writes the files into a fresh repository root and checks its src/.
 *  @param array<string, string> $files paths relative to the root
 *  @return array{failures: list<string>, warnings: list<string>} */
function checkFiles(array $files): array
{
    $root = sys_get_temp_dir().'/fides-test-'.bin2hex(random_bytes(6));
    foreach ($files as $path => $content) {
        @mkdir(dirname("$root/$path"), 0777, true);
        file_put_contents("$root/$path", $content);
    }

    return checkSourceTree("$root/src", $root);
}

/** A PHP file in namespace Mnemon\<namespace>. Lines of $body join with "\n". */
function php(string $namespace, string ...$body): string
{
    return "<?php\n/** Test unit. */\nnamespace Mnemon\\$namespace;\n\n"
        .implode("\n", $body)."\n";
}

/** The same without the header comment. */
function phpWithoutHeader(string $namespace, string $body): string
{
    return "<?php\nnamespace Mnemon\\$namespace;\n\n$body\n";
}

function failuresOf(array $files): array
{
    return checkFiles($files)['failures'];
}

/** @param list<string> $messages */
function mentions(array $messages, string $needle): bool
{
    return array_filter($messages, static fn ($m) => str_contains($m, $needle)) !== [];
}

$wiring = static fn (string ...$uses) => php('Delivery', ...[
    'use Illuminate\Support\ServiceProvider;',
    ...array_map(static fn ($use) => "use $use;", $uses),
    'final class Wiring extends ServiceProvider {}',
]);

$tests = [
    'an_empty_src_passes' => fn () => failuresOf([]) === [],

    'an_entity_may_not_import_another_entity' => fn () => mentions(failuresOf([
        'src/Entities/Drawer/Drawer.php' => php(
            'Entities\Drawer',
            'use Mnemon\Entities\Wing\Wing;',
            'final class Drawer {}',
        ),
    ]), 'may not import Mnemon\Entities\Wing\Wing'),

    'a_feature_may_import_entities_but_not_features' => function () {
        $failures = failuresOf(['src/Features/AddDrawer/AddDrawer.php' => php(
            'Features\AddDrawer',
            'use Mnemon\Entities\Drawer\Drawer;',
            'use Mnemon\Features\Recall\Recall;',
            'final class AddDrawer {}',
        )]);

        return mentions($failures, 'Features\Recall') && ! mentions($failures, 'Entities\Drawer');
    },

    'the_core_may_not_import_the_framework' => fn () => mentions(failuresOf([
        'src/Shared/Clock.php' => php(
            'Shared',
            'use Illuminate\Support\Facades\Date;',
            'interface Clock {}',
        ),
    ]), 'may not import Illuminate'),

    'fully_qualified_names_count_as_imports' => fn () => mentions(failuresOf([
        WING => php('Entities\Wing', 'final class Wing {', '    public function all()', '    {',
            '        return \App\Models\Wing::query();', '    }', '}'),
    ]), 'may not import App\Models\Wing'),

    'grouped_imports_are_each_checked' => fn () => mentions(failuresOf([
        WING => php(
            'Entities\Wing',
            'use Mnemon\Entities\{Wing\Slug, Room\Room};',
            'final class Wing {}',
        ),
    ]), 'Mnemon\Entities\Room\Room'),

    'only_integrations_may_wrap_old_code' => function () {
        $failures = failuresOf([
            'src/Integrations/Eloquent/EloquentWings.php' => php(
                'Integrations\Eloquent',
                'use App\Models\Wing;',
                'final class EloquentWings {}',
            ),
            'src/Delivery/Mcp/AddDrawerTool.php' => php(
                'Delivery\Mcp',
                'use App\Models\Drawer;',
                'final class AddDrawerTool {}',
            ),
        ]);

        return mentions($failures, 'AddDrawerTool.php: may not import App')
            && ! mentions($failures, 'EloquentWings');
    },

    'only_the_wiring_file_names_integrations' => function () use ($wiring) {
        $failures = failuresOf([
            'src/Delivery/Wiring.php' => $wiring('Mnemon\Integrations\Eloquent\EloquentWings'),
            'src/Delivery/Mcp/Tool.php' => php(
                'Delivery\Mcp',
                'use Mnemon\Integrations\Eloquent\EloquentWings;',
                'final class Tool {}',
            ),
        ]);

        return mentions($failures, 'Mcp/Tool.php: may not import')
            && ! mentions($failures, 'Wiring');
    },

    'the_wiring_file_may_name_the_entity_contracts_it_binds' => function () use ($wiring) {
        $contract = [
            'src/Entities/Wing/WingStore.php' => php('Entities\Wing', 'interface WingStore {}'),
        ];
        $bound = ['src/Delivery/Wiring.php' => $wiring('Mnemon\Entities\Wing\WingStore')];
        $elsewhere = ['src/Delivery/Mcp/Tool.php' => php(
            'Delivery\Mcp',
            'use Mnemon\Entities\Wing\WingStore;',
            'final class Tool {}',
        )];

        return failuresOf($contract + $bound) === []
            && mentions(failuresOf($contract + $bound + $elsewhere), 'Tool.php: may not import');
    },

    'no_inheritance_outside_the_edge_list' => fn () => mentions(failuresOf([
        'src/Features/AddDrawer/AddDrawer.php' => php(
            'Features\AddDrawer',
            'final class AddDrawer extends Base {}',
        ),
    ]), 'extends Base'),

    'a_custom_error_may_extend_a_built_in_exception' => fn () => failuresOf([
        'src/Entities/Wing/WingNotFound.php' => php(
            'Entities\Wing',
            'final class WingNotFound extends \RuntimeException {}',
        ),
    ]) === [],

    'the_namespace_matches_the_folder' => fn () => mentions(failuresOf([
        WING => php('Entities\Room', 'final class Wing {}'),
    ]), 'must match its folder'),

    'paths_are_zones_pascal_case_and_shallow' => function () {
        $failures = failuresOf([
            'src/Services/Thing.php' => php('Services', 'final class Thing {}'),
            'src/Entities/wing/Wing.php' => php('Entities\wing', 'final class Wing {}'),
            'src/Entities/Wing/Deep/Wing.php' => php('Entities\Wing\Deep', 'final class Wing {}'),
        ]);

        return mentions($failures, 'not a zone') && mentions($failures, 'PascalCase')
            && mentions($failures, 'deeper than 3');
    },

    'feature_groups_are_all_or_none' => fn () => mentions(failuresOf([
        'src/Features/Palace/AddDrawer/AddDrawer.php' => php(
            'Features\Palace\AddDrawer',
            'final class AddDrawer {}',
        ),
        'src/Features/Recall/Recall.php' => php('Features\Recall', 'final class Recall {}'),
    ]), 'mixes grouped and ungrouped'),

    'every_file_opens_with_a_header' => fn () => mentions(failuresOf([
        WING => phpWithoutHeader('Entities\Wing', 'final class Wing {}'),
    ]), 'must open with a comment'),

    'a_todo_in_a_comment_names_its_issue' => function () {
        $wing = static fn (string $comment) => [
            WING => php('Entities\Wing', $comment, 'final class Wing {}'),
        ];
        $bad = failuresOf($wing('// TODO tidy'));
        $good = failuresOf($wing('// TODO(#12) tidy'));
        $inString = failuresOf([WING => php('Entities\Wing', 'final class Wing {',
            "    public string \$label = 'TODO';", '}')]);

        return mentions($bad, 'TODO') && $good === [] && $inString === [];
    },

    'a_named_decision_has_a_record' => function () {
        $file = php('Entities\Wing', '// Decision: ids-are-uuids', 'final class Wing {}');
        $record = ['docs/decisions/ids-are-uuids.md' => "Ids are UUIDs.\n"];

        return mentions(failuresOf([WING => $file]), 'ids-are-uuids')
            && failuresOf([WING => $file] + $record) === [];
    },

    'lines_stay_within_100_characters' => fn () => mentions(failuresOf([
        WING => php('Entities\Wing', '// '.str_repeat('x', 100), 'final class Wing {}'),
    ]), 'longer than 100'),

    'size_warns_past_200_code_lines_and_fails_past_300' => function () {
        $wing = static fn (int $fields) => [WING => php('Entities\Wing', ...[
            'final class Wing {',
            ...array_fill(0, $fields, '    public int $a = 1;'),
            '}',
        ])];
        $warned = checkFiles($wing(240));

        return $warned['failures'] === [] && mentions($warned['warnings'], 'aim 200')
            && mentions(failuresOf($wing(320)), 'max 300');
    },

    'comments_and_blank_lines_do_not_count_toward_size' => fn () => checkFiles([
        WING => php('Entities\Wing', ...[...array_fill(0, 400, "// why\n"), 'final class Wing {}']),
    ])['warnings'] === [],

    'every_contract_is_bound_in_the_wiring_file' => function () use ($wiring) {
        $contract = ['src/Shared/Clock.php' => php('Shared', 'interface Clock {}')];
        $bound = $contract + ['src/Delivery/Wiring.php' => $wiring('Mnemon\Shared\Clock')];

        return mentions(failuresOf($contract), 'Mnemon\Shared\Clock is not bound')
            && failuresOf($bound) === [];
    },

    'the_ratchet_fails_when_a_count_goes_up_or_is_not_locked_in' => function () {
        $up = compareRatchet(['inheritance' => 5], ['inheritance' => 6]);
        $down = compareRatchet(['inheritance' => 5], ['inheritance' => 4]);
        $same = compareRatchet(['inheritance' => 5], ['inheritance' => 5]);

        return mentions($up['failures'], 'went up')
            && mentions($down['failures'], '--update-ratchet')
            && $same['failures'] === [];
    },
];

$failed = 0;
foreach ($tests as $name => $test) {
    $passed = $test();
    $failed += $passed ? 0 : 1;
    echo ($passed ? 'ok   ' : 'FAIL ')."$name\n";
}
$total = count($tests);
echo $failed === 0 ? "$total tests passed\n" : "$failed of $total failed\n";
exit($failed === 0 ? 0 : 1);
