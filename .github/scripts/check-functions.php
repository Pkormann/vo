<?php
/**
 * Appels à des fonctions inexistantes — ce que `php -l` ne voit pas.
 *
 * Une fonction manquante n'est pas une erreur de syntaxe : elle ne pète qu'à
 * l'exécution, c'est-à-dire en production. On analyse donc les vrais jetons PHP
 * (pas le texte), ce qui écarte le SQL, les commentaires et le JavaScript.
 */
$root  = realpath(__DIR__ . '/../..');
$files = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $f) {
    $p = $f->getPathname();
    if (str_ends_with($p, '.php') && !str_contains($p, '/.git/') && !str_contains($p, '/analyse/')) {
        $files[] = $p;
    }
}

// Fonctions définies par le projet
$defined = [];
foreach ($files as $file) {
    $tokens = token_get_all(file_get_contents($file));
    foreach ($tokens as $i => $t) {
        if (is_array($t) && $t[0] === T_FUNCTION) {
            for ($j = $i + 1; $j < count($tokens); $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $defined[strtolower($tokens[$j][1])] = true;
                    break;
                }
                if (!is_array($tokens[$j]) && $tokens[$j] === '(') { break; }  // fonction anonyme
            }
        }
    }
}

$internal = array_flip(array_map('strtolower', get_defined_functions()['internal']));
$problems = [];

foreach ($files as $file) {
    $tokens = token_get_all(file_get_contents($file));
    $count  = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING) { continue; }

        // suivi d'une parenthèse ouvrante ?
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
        if ($j >= $count || $tokens[$j] !== '(') { continue; }

        // précédé de -> :: function new ? alors ce n'est pas un appel de fonction libre
        $k = $i - 1;
        while ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) { $k--; }
        if ($k >= 0 && is_array($tokens[$k])
            && in_array($tokens[$k][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
            continue;
        }

        $name = strtolower($t[1]);
        if (isset($defined[$name]) || isset($internal[$name])) { continue; }

        $problems[] = sprintf('%s:%d  %s()', str_replace($root . '/', '', $file), $t[2], $t[1]);
    }
}

if ($problems) {
    echo "⚠ Fonctions appelées mais jamais définies :\n";
    foreach (array_unique($problems) as $p) { echo '  ' . $p . "\n"; }
    exit(1);
}

echo '✓ Aucun appel orphelin (' . count($defined) . " fonctions définies dans le projet)\n";
