#!/usr/bin/env node
/**
 * Vérifie que TOUS les scripts (prerequest + test) de la collection sont du
 * JavaScript syntaxiquement valide.
 *
 * POURQUOI CE CONTRÔLE EXISTE : une apostrophe non échappée dans une chaîne
 * JavaScript (`'le payload n'expose pas'`) ne produit pas une assertion rouge
 * bien localisée, elle fait échouer le SCRIPT ENTIER de la requête. Le jeton
 * n'est alors jamais capturé, et les 40 requêtes suivantes répondent 401 —
 * une cascade de failures qui ne dit rien de la cause réelle.
 *
 * Usage : node scripts/validate_postman_collection.mjs <collection.json>
 */
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const chemin = process.argv[2];
if (!chemin) {
    console.error('usage : node validate_postman_collection.mjs <collection.json>');
    process.exit(2);
}

const collection = JSON.parse(readFileSync(chemin, 'utf8'));
let verifies = 0;
let ko = 0;

function walker(items, prefix = '') {
    for (const item of items ?? []) {
        if (item.item) {
            walker(item.item, `${prefix}${item.name} / `);
            continue;
        }
        for (const evt of item.event ?? []) {
            const code = (evt.script?.exec ?? []).join('\n');
            const ou = `${prefix}${item.name} [${evt.listen}]`;
            try {
                // `new vm.Script` compile sans exécuter : c'est exactement le
                // contrôle de syntaxe que Postman/Newman font au lancement.
                new vm.Script(`(async function(){ ${code} })`, { filename: ou });
                verifies++;
            } catch (e) {
                ko++;
                console.error(`  SYNTAXE INVALIDE — ${ou}`);
                console.error(`    ${e.message}`);
                const ligne = (e.stack ?? '').split('\n').find((l) => l.includes('evalmachine') || l.includes(':'));
                if (ligne) console.error(`    ${ligne.trim()}`);
            }
        }
    }
}

walker(collection.item);

console.log(`scripts vérifiés : ${verifies}, invalides : ${ko}`);
if (ko > 0) {
    console.error('CORRIGE les scripts ci-dessus avant de lancer la collection.');
    process.exit(1);
}
console.log('tous les scripts sont syntaxiquement valides');
