<?php
declare(strict_types=1);

/**
 * Pays reconnus pour la normalisation E.164.
 *  - dial            : indicatif sans « + »
 *  - national_length : longueurs valides du numéro national, sans le 0 initial
 *  - operators       : (optionnel) opérateur => préfixes à 2 chiffres du numéro national
 *
 * ⚠ Les préfixes opérateurs de la RDC ci-dessous sont à VALIDER avec vos partenaires télécoms :
 * les plages peuvent évoluer. Un préfixe inconnu n'invalide pas le numéro (operator = null).
 */
return [
    'countries' => [
        'CD' => ['name' => 'RD Congo', 'dial' => '243', 'national_length' => [9], 'operators' => [
            'vodacom' => ['81', '82', '83'],
            'airtel' => ['97', '98', '99'],
            'orange' => ['84', '85', '89'],
            'africell' => ['90', '91'],
        ]],
        'CG' => ['name' => 'Congo-Brazzaville', 'dial' => '242', 'national_length' => [9]],
        'RW' => ['name' => 'Rwanda', 'dial' => '250', 'national_length' => [9]],
        'BI' => ['name' => 'Burundi', 'dial' => '257', 'national_length' => [8]],
        'UG' => ['name' => 'Ouganda', 'dial' => '256', 'national_length' => [9]],
        'KE' => ['name' => 'Kenya', 'dial' => '254', 'national_length' => [9]],
        'TZ' => ['name' => 'Tanzanie', 'dial' => '255', 'national_length' => [9]],
        'ZM' => ['name' => 'Zambie', 'dial' => '260', 'national_length' => [9]],
        'AO' => ['name' => 'Angola', 'dial' => '244', 'national_length' => [9]],
        'ZA' => ['name' => 'Afrique du Sud', 'dial' => '27', 'national_length' => [9]],
        'NG' => ['name' => 'Nigeria', 'dial' => '234', 'national_length' => [10]],
        'GH' => ['name' => 'Ghana', 'dial' => '233', 'national_length' => [9]],
        'SN' => ['name' => 'Sénégal', 'dial' => '221', 'national_length' => [9]],
        'CI' => ['name' => 'Côte d’Ivoire', 'dial' => '225', 'national_length' => [10]],
        'CM' => ['name' => 'Cameroun', 'dial' => '237', 'national_length' => [9]],
        'MA' => ['name' => 'Maroc', 'dial' => '212', 'national_length' => [9]],
        'FR' => ['name' => 'France', 'dial' => '33', 'national_length' => [9]],
        'BE' => ['name' => 'Belgique', 'dial' => '32', 'national_length' => [8, 9]],
        'US' => ['name' => 'États-Unis / Canada', 'dial' => '1', 'national_length' => [10]],
    ],
];
