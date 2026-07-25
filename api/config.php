<?php
/**
 * Configurazione Guida TV — Casa Gorgonzola
 *
 * Modifica SOLO i valori qui sotto. Questo file non deve mai essere
 * accessibile via browser come testo semplice: su Apache l'.htaccess
 * incluso in questa cartella già blocca l'accesso diretto ai file .php
 * "di supporto", ma qui viene comunque eseguito da PHP e mai stampato.
 */

return [

    // Elenco di sorgenti XMLTV da provare in ordine. La prima che risponde
    // con dati validi viene usata e messa in cache. Aggiungi/rimuovi pure.
    'epg_sources' => [
        'https://iptv-epg.org/files/epg-it.xml',
        'https://epg.pm/xmltv.xml?lang=it',
    ],

    // Per quanti secondi tenere in cache l'XMLTV scaricato prima di ricontattare
    // le fonti remote. 1800 = 30 minuti è un buon compromesso per una guida TV.
    'epg_cache_ttl' => 1800,

    // Se tutte le fonti falliscono, per quanto tempo massimo è accettabile
    // servire ancora la cache "vecchia" invece di mostrare un errore secco
    // (in secondi). 21600 = 6 ore.
    'epg_stale_max_age' => 21600,

    // Timeout per singola richiesta HTTP verso le fonti EPG (secondi).
    'epg_fetch_timeout' => 20,

    // La tua API key di TMDB (https://www.themoviedb.org/settings/api).
    // Non viene MAI inviata al browser: resta solo su questo server.
    'tmdb_api_key' => 'ad3bf71ff4d755f58d96be9435142518',

    // Per quanto tempo (secondi) tenere in cache il risultato TMDB di un
    // singolo titolo. 604800 = 7 giorni: i poster non cambiano spesso.
    'tmdb_cache_ttl' => 604800,

    'tmdb_fetch_timeout' => 6,

    // User-Agent onesto da presentare alle fonti remote.
    'user_agent' => 'GuidaTV-CasaGorgonzola/1.0 (+https://www.marianhala.it)',

    // Cartella cache (relativa a questo file). Deve essere scrivibile dal
    // processo PHP (permessi 775 o 755 a seconda dell'hosting).
    'cache_dir' => __DIR__ . '/cache',
];
