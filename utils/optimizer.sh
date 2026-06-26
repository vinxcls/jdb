#!/bin/bash

# ============================================================
# CONFIGURAZIONE - Modifica questi percorsi in base al tuo setup
# ============================================================

# Percorso al binario di llama.cpp (può essere 'llama-cli' o 'main')
LLAMA_BIN="${LLAMA_BIN:-/home/vinx/llama.cpp/build/bin/llama-completion}"

# Percorso al modello GGUF (es. Qwen2.5-Coder-7B-Instruct-Q4_K_M.gguf)
MODEL_PATH="${MODEL_PATH:-/home/vinx/models/Qwopus-GLM-DareTies-Q4_K_M.gguf}"

if [ ! -x "$LLAMA_BIN" ] && [ ! -f "$LLAMA_BIN" ]; then
    echo "Errore: Binario llama.cpp non trovato: $LLAMA_BIN"
    echo "Imposta LLAMA_BIN con il percorso corretto."
    exit 1
fi

if [ ! -f "$MODEL_PATH" ]; then
    echo "Errore: Modello non trovato: $MODEL_PATH"
    echo "Imposta MODEL_PATH con il percorso del file GGUF."
    exit 1
fi
# Parametri del modello
CTX_SIZE=8192          # Context size (aumenta se opcode molto lunghi)
THREADS=$(nproc)       # Thread CPU (o usa $(sysctl -n hw.ncpu) su macOS)
TEMP=0.2               # Temperatura bassa per output deterministico
TOP_P=0.9
TOP_K=40
MAX_TOKENS=4096        # Max token in output

className=$(basename "$1" .php)
dirName=$(dirname $1)
opfile=$dirName/$className.opcode
php -d opcache.enable_cli=1 -d opcache.opt_debug_level=0x10000 $1 > $opfile 2>&1


awk '
# Rileva righe con :: (separatori di funzione/metodo)
/'"$className"'::/ {
    # Estrae il nome completo (Classe::metodo)
    func_name = $0

    # Rimuove spazi iniziali/finali
    gsub(/^[ \t]+|[ \t]+$/, "", func_name)

    # Sostituisce :: con _ per il nome del file
    gsub(/::/, "_", func_name)

    # Rimuove eventuali altri caratteri non validi per filename
    gsub(/[^a-zA-Z0-9_]/, "", func_name)

    # Costruisce il nome del file di output
    outfile = "'"$dirName"'/" func_name ".opcode"

    # Pulisce il file se esiste già
    printf "" > outfile

    # Flag per indicare che siamo dentro una funzione
    in_function = 1

    next
}

# Riga vuota = fine della funzione corrente
/^$/ {
    in_function = 0
    next
}

# Se siamo dentro una funzione, scrivi la riga
in_function == 1 && outfile != "" {
    print $0 >> outfile
}
' "$opfile"

rm $opfile

for i in $dirName/*.opcode
do

    OUTPUT_FILE=$(echo "$i" | sed 's/\.opcode$/.analysis.md/')

    OPCODE_CONTENT=$(cat "$i")

    METHOD_NAME=$(basename "$i" | sed 's/.*\.\(.*\)\.opcode$/\1/' | sed 's/_/::/')

    read -r -d '' SYSTEM_PROMPT << 'EOF'
Sei un esperto di performance PHP che analizza opcode generati da OPcache.
Il tuo compito NON è riscrivere il codice, ma IDENTIFICARE le ottimizzazioni possibili.

REGOLE FONDAMENTALI:
1. NON generare codice PHP. Fornisci SOLO un elenco di suggerimenti.
2. Ogni suggerimento deve riferirsi a istruzioni opcode specifiche (cita i numeri di riga o le istruzioni esatte).
3. Sii conservativo: suggerisci solo ottimizzazioni di cui sei SICURO al 100%.
4. Se non sei sicuro di qualcosa, NON includerlo nell'elenco. Meglio meno suggerimenti ma corretti.
5. Considera i vincoli PHP 5.5 (niente typed properties, niente ??, niente [] per array, ecc.).

CATEGORIE DI OTTIMIZZAZIONE DA CERCARE:
- Chiamate a funzione ridondanti o ripetute in loop
- Fetch di variabili/array non necessari (es. ZEND_FETCH_DIM_R ripetuti sullo stesso indice)
- Assegnazioni temporanee eliminabili
- Condizioni semplficabili (JMPS ridondanti)
- Concatenazioni di stringhe inefficienti
- Pattern che in PHP sorgente potrebbero essere riscritti in modo più pulito

FORMATO DI RISPOSTA OBBLIGATORIO:
Rispondi SOLO con un elenco numerato in questo formato esatto:

## Analisi opcode: [nome metodo]

### Ottimizzazioni suggerite

1. **[Righe X-Y]** - [Breve titolo]
   - Opcode coinvolto: [istruzione specifica]
   - Problema: [descrizione del problema]
   - Suggerimento: [cosa fare nel codice sorgente]
   - Beneficio atteso: [performance/memoria/leggibilità]

2. ...

### Note
- [Eventuali osservazioni generali sul metodo]

Se NON trovi ottimizzazioni significative, rispondi semplicemente:
"## Analisi opcode: [nome metodo]
Nessuna ottimizzazione significativa identificata. Il codice è già efficiente."

NON aggiungere codice PHP. NON aggiungere spiegazioni fuori dallo schema.
EOF

    USER_PROMPT="Analizza questo opcode del metodo ${METHOD_NAME} e fornisci SOLO l'elenco delle ottimizzazioni suggerite, seguendo rigorosamente il formato richiesto.

\`\`\`
${OPCODE_CONTENT}
\`\`\`"

    echo "==> Analizzo: $INPUT_FILE"
    echo "==> Modello: $(basename "$MODEL_PATH")"
    echo "==> Output:  $OUTPUT_FILE"
    echo ""

    # Costruiamo il prompt completo (system + user)
    FULL_PROMPT="<|system|>
    ${SYSTEM_PROMPT}
    <|user|>
    ${USER_PROMPT}
    <|assistant|>"

    # Esecuzione con llama-cli (formato ChatML/Qwen)
    "$LLAMA_BIN" \
        -m "$MODEL_PATH" \
        -c "$CTX_SIZE" \
        -t "$THREADS" \
        --temp "$TEMP" \
        --top-p "$TOP_P" \
        --top-k "$TOP_K" \
        -n "$MAX_TOKENS" \
        --no-conversation \
        --no-display-prompt \
        -p "$FULL_PROMPT" 2>/dev/null | \
        tee /tmp/llama_raw_output.txt

    # ============================================================
    # POST-PROCESSING: estrai solo il blocco PHP
    # ============================================================

    mv /tmp/llama_raw_output.txt $OUTPUT_FILE


    echo ""
    echo "✅ Salvato in: $OUTPUT_FILE"
    echo ""
    rm /tmp/llama_raw_output.txt
done
