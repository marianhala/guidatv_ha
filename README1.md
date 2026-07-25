# Guida TV — Casa Gorgonzola (versione deployabile)

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

## Cosa è cambiato rispetto al file HTML originale

Il file che mi hai mandato era un ottimo prototipo, ma per poterlo
pubblicare su un hosting reale e farlo funzionare in modo affidabile ho
risolto questi problemi:

1. **Niente più proxy CORS di terze parti** (`allorigins.win`,
   `corsproxy.io`, `cors.isomorphic-git.org`). Erano il punto più fragile:
   servizi gratuiti, senza SLA, spesso lenti o offline, e in più il
   traffico del tuo sito passava per server di sconosciuti. Ora
   `api/epg.php` scarica l'XMLTV direttamente dal tuo hosting (richiesta
   server-to-server, zero problemi di CORS) e lo mette in cache su disco.
2. **Cache server-side dell'EPG** (30 minuti, configurabile): prima ogni
   visitatore scaricava l'intero file XMLTV (spesso diversi MB) da zero;
   ora lo fa il server una volta ogni mezz'ora al massimo, e serve tutti
   i visitatori dalla cache locale — molto più veloce e più rispettoso
   verso le fonti EPG pubbliche.
3. **Fallback "stale cache"**: se tutte le fonti EPG risultano irraggiungibili,
   il sito continua a mostrare l'ultimo EPG scaricato con buon esito (fino
   a 6 ore) invece di andare subito in errore, segnalandolo comunque in
   modo visibile ("CACHE VECCHIA").
4. **API key TMDB non più esposta nel browser**: prima veniva salvata in
   `localStorage` e inviata in chiaro in ogni URL verso TMDB (chiunque
   apra i DevTools la vede). Ora vive solo in `api/config.php` sul server;
   il client chiama `api/tmdb.php?title=...` e riceve solo poster/trama.
5. **Cache anche per TMDB** (7 giorni per titolo): riduce drasticamente le
   chiamate verso TMDB e velocizza il caricamento dei poster ai
   caricamenti successivi.
6. **Protezione da SSRF** sulla sorgente EPG personalizzata: se imposti un
   URL EPG custom dal pannello Config, il server rifiuta IP privati/loopback
   (es. `127.0.0.1`, `192.168.x.x`) per evitare che il tuo hosting possa
   essere usato come proxy verso la tua rete interna.
7. **Migliorie minori**: `aria-live` sullo stato EPG per la sintesi vocale,
   pulizia del pannello di configurazione (un campo in meno da compilare).

## Cosa NON ho toccato (di proposito)

- L'integrazione con Home Assistant resta lato client (URL + token
  salvati in `localStorage` del browser): è corretto così, perché il tuo
  hosting web pubblico normalmente **non può raggiungere** la tua Home
  Assistant sulla rete locale di casa — è il tuo browser, sulla stessa
  rete di casa, a doverci parlare direttamente.
- Tutta l'estetica, il layout, l'orologio "Casio" e la logica di
  rendering dei canali sono rimasti identici: erano già solidi.

## Un avviso sul token Home Assistant

Il token lungo di Home Assistant resta salvato in chiaro nel
`localStorage` del browser che usi per aprire la pagina. Per un uso
familiare va bene, ma tieni presente che chiunque avesse accesso fisico o
software a quel browser potrebbe leggerlo. Se vuoi, in futuro si può
creare un utente Home Assistant dedicato con permessi ridotti (solo
`media_player.*`) invece di usare il tuo token amministrativo principale.
