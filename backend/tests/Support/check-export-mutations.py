from pathlib import Path
import subprocess
import tempfile
root = Path(__file__).resolve().parents[3]
exporter = root / 'backend/src/Service/VaultExporter.php'
parser = root / 'backend/src/Service/FrontmatterParser.php'
mutations = [
    ('single collision check', exporter, 'for ($suffix = 0; isset(', 'for ($suffix = 0; $suffix < 1 && isset(', 'testEveryCollidingNote'),
    ('folders not checked', exporter, ' || isset($folders[mb_strtolower($name)]); ++$suffix)', '; ++$suffix)', 'testAFileNamedLikeAFolder'),
    ('file not moved aside for a folder', exporter, 'if (isset($files[$key])) {', 'if (false && isset($files[$key])) {', 'testAFileNamedLikeAFolder'),
    ('unquoted tag scalars', exporter, "json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)", "('['.implode(', ', $tags).']')", 'testTagsRemain'),
    ('body ltrim', parser, "preg_replace('/^\\R/', '', $m[2], 1)", 'ltrim($m[2])', 'testBodyBytes'),
    ('ignored zip close failure', exporter, 'if (!@$zip->close())', 'if (!@$zip->close() && false)', 'testFailedArchiveFinalization'),
    ('left partial archive', exporter, 'if (!$complete && is_file($path))', 'if (false && !$complete && is_file($path))', 'testInterruptedExport'),
]
for name, file, before, after, test in mutations:
    with tempfile.TemporaryDirectory(prefix='memex-export-mutant-') as temp:
        temp = Path(temp)
        source = file.read_text()
        assert before in source, name
        (temp / 'mutant.php').write_text(source.replace(before, after, 1))
        (temp / 'bootstrap.php').write_text("<?php require '" + str(root / 'backend/vendor/autoload.php') + "'; require '" + str(temp / 'mutant.php') + "';")
        run = subprocess.run(['php', str(root / 'backend/vendor/bin/phpunit'), '--no-configuration', '--bootstrap', str(temp / 'bootstrap.php'), '--filter', test, str(root / 'backend/tests/Service/ExportPortabilityTest.php')], text=True, capture_output=True)
        assert run.returncode != 0 and 'failure' in run.stdout.lower(), (name, run.stdout, run.stderr)
        print('KILLED: ' + name)
        print(run.stdout[run.stdout.index('There '):])
