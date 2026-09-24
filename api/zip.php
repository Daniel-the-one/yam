<?php

$zip = new ZipArchive;

$file = 'api.zip'; // Nom du fichier ZIP

if ($zip->open($file) === TRUE) {

    $zip->extractTo('./'); // Décompression dans le dossier courant

    $zip->close();

    echo 'Décompression réussie !';

} else {

    echo 'Impossible d\'ouvrir le fichier ZIP.';

}

?>

