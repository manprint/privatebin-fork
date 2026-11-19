Voglio fare le seguenti evolutive al progetto:

0. Deve essere possibile dare un nome al paste da home page (esempio: my-paste, script-bash01, etc); il nome del paste defe essere anche l'id del paste e devo poter copiare anche il link con url/mypaste url/script etc oltre che con il nome gia attualmente generato. Deve essere possibile anche assegnare un tag al paste, esempio: "code", "shell", "java", etc.
1) Introdurre un area admin, accessibil con un pulsante Admin nella home a cui si può accedere con un token alfanumerico di 15 caratteri.

2) Nell'area admin deve essere possibile vedere tutti i paste effettuati e fare inoltre le seguenti operazioni:

   - CRUD completa sul paste: modifica, elimina, prolunga durata (o rendi il paste senza scadenza)

   - Modificare il nome del paste

   - Ricerca tra i paste (anche all'interno del testo dei passe stessi); la ricerca deve e avere filtri anche per data inderimento, e la tabella mostrata dei paste potrà essere ordinata per data crescente/decrescente di visualizzazione.

3) Il testo raw di un paste deve poter essere scarivabile tramite script curl in modo semplice. Suggerisci nel paste creato il comando per scaricare il file in formato raw con curl o wget.

Fammi questi sviluppi, aggiungi tutti i test del caso e verifica le regressioni. Voglio un laoro fatto bene e di alto livello.
