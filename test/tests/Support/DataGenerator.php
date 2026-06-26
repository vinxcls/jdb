<?php

namespace Tests\Support;

/**
 * DataGenerator – genera record realistici per lo stress test di JsonDatabase.
 *
 * Genera profili utente con:
 *   - Dati anagrafici (nome, cognome, email, telefono)
 *   - Dati indirizzo (città, CAP, paese)
 *   - Payload variabile (bio, tag, metadati) per stressare JSON encode/decode
 *
 * Variante "heavy" con payload ~2 KB per testare l'impatto della dimensione
 * del record sulla velocità di fseek/fread.
 */
class DataGenerator
{
    private static $firstNames = [
        'Luca','Marco','Giulia','Valentina','Andrea','Chiara','Francesco','Sara',
        'Alessandro','Elena','Matteo','Federica','Davide','Martina','Simone','Laura',
        'Giuseppe','Alice','Roberto','Elisa','Stefano','Irene','Antonio','Beatrice',
        'Giovanni','Silvia','Riccardo','Paola','Fabio','Roberta',
    ];

    private static $lastNames = [
        'Rossi','Ferrari','Esposito','Bianchi','Romano','Colombo','Ricci','Marino',
        'Greco','Bruno','Gallo','Conti','De Luca','Costa','Mancini','Giordano',
        'Rizzo','Lombardi','Moretti','Barbieri','Fontana','Santoro','Marini','Fabbri',
        'Russo','Ferrara','Caruso','Leone','Longo','Gentile',
    ];

    private static $cities = [
        'Roma','Milano','Napoli','Torino','Palermo','Genova','Bologna','Firenze',
        'Bari','Catania','Venezia','Verona','Messina','Padova','Trieste',
        'Taranto','Brescia','Prato','Parma','Modena',
    ];

    private static $domains = [
        'gmail.com','yahoo.it','hotmail.it','libero.it','outlook.com',
        'virgilio.it','alice.it','tin.it','fastwebnet.it','tiscali.it',
    ];

    private static $tags = [
        'premium','standard','free','vip','trial','suspended','verified',
        'unverified','admin','moderator','editor','viewer','guest',
    ];

    private static $loremWords = [
        'lorem','ipsum','dolor','sit','amet','consectetur','adipiscing','elit',
        'sed','do','eiusmod','tempor','incididunt','ut','labore','et','dolore',
        'magna','aliqua','enim','ad','minim','veniam','quis','nostrud',
        'exercitation','ullamco','laboris','nisi','aliquip','ex','ea','commodo',
        'consequat','duis','aute','irure','in','reprehenderit','voluptate',
        'velit','esse','cillum','fugiat','nulla','pariatur','excepteur','sint',
        'occaecat','cupidatat','non','proident','sunt','culpa','qui','officia',
        'deserunt','mollit','anim','id','est','laborum',
    ];

    /**
     * Genera un record utente standard (~300-500 bytes JSON).
     */
    public static function makeUser(int $seed = 0): array
    {
        mt_srand($seed);

        $firstName = self::$firstNames[mt_rand(0, count(self::$firstNames) - 1)];
        $lastName  = self::$lastNames[mt_rand(0, count(self::$lastNames) - 1)];
        $city      = self::$cities[mt_rand(0, count(self::$cities) - 1)];
        $domain    = self::$domains[mt_rand(0, count(self::$domains) - 1)];

        $username = strtolower($firstName) . '.' . strtolower(str_replace(' ', '', $lastName))
                    . mt_rand(10, 999);

        return [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'email'      => $username . '@' . $domain,
            'phone'      => '+39 3' . mt_rand(10, 99) . ' ' . mt_rand(1000000, 9999999),
            'city'       => $city,
            'zip'        => str_pad((string) mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT),
            'country'    => 'IT',
            'age'        => mt_rand(18, 80),
            'score'      => round(mt_rand(0, 10000) / 100, 2),
            'active'     => (bool) mt_rand(0, 1),
            'role'       => self::$tags[mt_rand(0, count(self::$tags) - 1)],
            'created_at' => date('Y-m-d H:i:s', mt_rand(strtotime('2018-01-01'), time())),
            'tags'       => array_slice(
                self::$tags,
                mt_rand(0, count(self::$tags) - 3),
                mt_rand(1, 3)
            ),
            'metadata'   => [
                'source'     => ['organic', 'paid', 'referral', 'direct'][mt_rand(0, 3)],
                'ip'         => mt_rand(1, 255) . '.' . mt_rand(0, 255) . '.'
                                . mt_rand(0, 255) . '.' . mt_rand(0, 255),
                'user_agent' => 'Mozilla/5.0 (seed=' . $seed . ')',
                'visits'     => mt_rand(1, 5000),
            ],
        ];
    }

    /**
     * Genera un record "pesante" (~2 KB JSON) con una bio lunga.
     * Utile per stressare fread e json_decode.
     */
    public static function makeHeavyUser(int $seed = 0): array
    {
        $user = self::makeUser($seed);

        // Bio di circa 1500 caratteri
        mt_srand($seed + 1);
        $words = [];
        for ($i = 0; $i < 200; $i++) {
            $words[] = self::$loremWords[mt_rand(0, count(self::$loremWords) - 1)];
        }
        $user['bio'] = implode(' ', $words);

        // Array di preferenze aggiuntive
        $user['preferences'] = [];
        for ($i = 0; $i < 20; $i++) {
            $user['preferences']['key_' . $i] = 'value_' . mt_rand(1000, 9999);
        }

        return $user;
    }

    /**
     * Genera un batch di N record utente.
     *
     * @param int  $count
     * @param bool $heavy
     * @return array[]
     */
    public static function makeBatch(int $count, bool $heavy = false): array
    {
        $records = [];
        for ($i = 0; $i < $count; $i++) {
            $records[] = $heavy
                ? self::makeHeavyUser($i)
                : self::makeUser($i);
        }
        return $records;
    }
}
