
/**
 * ──────────────────────────────────────────────
 * TypeScript globale Window uitbreiding
 * ──────────────────────────────────────────────
 *
 * 1️⃣  export {}  
 *     - Maakt van dit bestand een ECHTE module.
 *     - Hierdoor vervuil je de globale scope niet 
 *       met variabelen, types of functies uit dit bestand.
 *     - TypeScript behandelt alles hierin als “afgesloten”
 *       tenzij je expliciet iets exporteert.
 *
 * 2️⃣  declare global { ... }
 *     - Opent bewust de 'global namespace' van het project.
 *     - Alles wat je hierbinnen definieert, wordt toegevoegd
 *       aan de globale types die overal gekend zijn.
 *     - Je gebruikt dit enkel wanneer iets wereldwijd 
 *       beschikbaar is (zoals window, document, console, etc.)
 *
 * 3️⃣  interface Window { ... }
 *     - Hier breid je de bestaande DOM-interface 'Window' uit.
 *     - TypeScript kent standaard veel browsereigenschappen,
 *       maar niet jouw eigen injecties vanuit PHP of andere code.
 *     - Door hier bijvoorbeeld `FORM_RULES` toe te voegen,
 *       vertel je TypeScript:
 *         “Verwacht dat window.FORM_RULES in runtime zal bestaan.”
 *
 * 4️⃣  Het resultaat:
 *     - In de rest van je project kun je veilig schrijven:
 *         window.FORM_RULES.schoolnaam
 *       inclusief autocompletion en typecontrole.
 *     - Je hoeft niets te importeren — het is projectbreed gekend.
 */
export {};

declare global {
  interface Window {
    FORM_RULES: Record<string, any>;
  }
}