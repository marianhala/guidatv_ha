# Guida TV — Casa (versione deployabile)
# guidatv_ha
Guida TV interattiva: palinsesto in tempo reale di oltre 30 canali italiani (Rai, Mediaset, La7, Discovery+), con navigazione per giorno, filtri per gruppo, ricerca programmi, poster e trame da TMDB, orologio stile Casio e comando diretto delle TV tramite Home Assistant. Estetica dark cyberpunk.
## Struttura

```
/
├── index.html          ← pagina principale (carica dati da api/)
└── api/
    ├── config.php       ← UNICO file da modificare: sorgenti EPG + chiave TMDB
    ├── epg.php          ← scarica/mette in cache l'XMLTV e lo serve al browser
    ├── tmdb.php         ← proxy TMDB (nasconde la API key al client)
    ├── .htaccess         ← blocca l'accesso diretto a config.php
    └── cache/            ← file di cache generati automaticamente (scrivibile)
        └── .htaccess     ← blocca l'accesso diretto ai file di cache
```

## Requisiti hosting

- PHP 7.4+ (va bene anche 8.x) con estensione **cURL** attiva (presente su
  praticamente tutti gli hosting condivisi: Aruba, Register, SiteGround,
  OVH, ecc.).
- Apache con supporto `.htaccess` (`AllowOverride All` sulla cartella, che
  è l'impostazione predefinita quasi ovunque). Su Nginx vedi nota sotto.
- Permessi di scrittura sulla cartella `api/cache/`.

## Installazione (5 minuti)

1. Carica tutta la cartella (`index.html` + `api/`) via FTP/SFTP nella
   cartella del tuo sito (es. `public_html/guida-tv/`).
2. Imposta i permessi della cartella `api/cache/` a **775** (o 755 se il tuo
   hosting esegue PHP come lo stesso utente proprietario dei file):
   ```
   chmod 775 api/cache
   ```
3. Apri `api/config.php` e:
   - lascia o modifica l'elenco `epg_sources` (sono le fonti XMLTV che il
     server prova in ordine — puoi aggiungerne altre se ne conosci di
     più affidabili per i canali italiani);
   - inserisci la tua **API key TMDB** gratuita al posto di
     `INSERISCI_QUI_LA_TUA_TMDB_API_KEY` (richiedila su
     https://www.themoviedb.org/settings/api — è gratis per uso personale).
     Se la lasci vuota, la guida funziona lo stesso ma senza poster/trame.
4. Apri `index.html` nel browser: al primo caricamento il server scarica
   l'EPG (può richiedere qualche secondo), poi lo tiene in cache 30 minuti.
5. Clicca **Config** in alto per impostare l'URL e il token di Home
   Assistant (questi restano solo nel tuo browser locale, via
   `localStorage`, non vengono mai inviati al tuo hosting).

Non serve creare database, non serve Node.js, non serve nessun processo in
background: è tutto file PHP classico, compatibile con la stragrande
maggioranza degli hosting condivisi "tradizionali".

## Nota per hosting Nginx

Se il tuo hosting usa Nginx invece di Apache, i file `.htaccess` vengono
ignorati. Aggiungi al tuo blocco `server {}` (o chiedi al supporto
dell'hosting di farlo):

```nginx
location ~ /api/cache/ {
    deny all;
    return 403;
}
location = /api/config.php {
    deny all;
    return 403;
}
```


## Un avviso sul token Home Assistant

Il token lungo di Home Assistant resta salvato in chiaro nel
`localStorage` del browser che usi per aprire la pagina. Per un uso
familiare va bene, ma tieni presente che chiunque avesse accesso fisico o
software a quel browser potrebbe leggerlo. Se vuoi, in futuro si può
creare un utente Home Assistant dedicato con permessi ridotti (solo
`media_player.*`) invece di usare il tuo token amministrativo principale.
