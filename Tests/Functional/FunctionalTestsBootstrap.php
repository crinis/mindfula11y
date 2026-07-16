<?php

declare(strict_types=1);

/*
 * Functional test bootstrap (customized copy of the testing-framework
 * boilerplate, as its header encourages).
 *
 * The extension repository is the composer root and has no TYPO3 web root of
 * its own, so this bootstrap provides one: a git-ignored .test-root/ directory
 * with the index.php marker Testbase::defineOriginalRootPath() requires. All
 * throw-away test instances (typo3temp/var/tests/*) are created inside it,
 * keeping them out of the repository root.
 */
(static function () {
    $root = dirname(__DIR__, 2) . '/.test-root';
    if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
        fwrite(STDERR, 'Unable to create functional test root ' . $root . PHP_EOL);
        exit(1);
    }
    if (!file_exists($root . '/index.php')) {
        file_put_contents($root . '/index.php', "<?php\n// Web root marker for typo3/testing-framework functional tests.\n");
    }
    putenv('TYPO3_PATH_ROOT=' . $root);

    $testbase = new \TYPO3\TestingFramework\Core\Testbase();
    $testbase->defineOriginalRootPath();
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
})();
