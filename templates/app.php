<?php
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" media="(prefers-color-scheme: light)" href='data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="%23000" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3" ry="3"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M9 15l2 2 4-4"/></svg>'>
    <link rel="icon" type="image/svg+xml" media="(prefers-color-scheme: dark)"  href='data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="%23fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3" ry="3"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M9 15l2 2 4-4"/></svg>'>
  <title>GeoFort Onderwijsaanvraag</title>
    <?= $viteService->renderTags('resources/js/main.ts'); ?>
</head>
<body>
  <!-- Hier komt jouw Vue App in terecht -->
  <div 
    id="app"
  >
    <h1>ONDERWIJS AANVRAAGFORMULIER</h1>
    <form 
      id="onderwijsFormulier" 
      method="post" 
      novalidate
    >
      <fieldset>
        <legend>BASISGEGEVENS</legend>
        <div class="flash-message__GeneralContainer--regular">
            <div 
                id="schoolnaamFout" 
                class="foute-invoermelding displayNone"
            ></div>
        </div>
        <label for="schoolnaam">Naam school</label>
        <input 
            type="text" 
            id="schoolnaam" 
            name="schoolnaam" 
            required
          >
      </fieldset>
      <button 
        type="submit" 
        class="verzendknop"
        id="verzendknop"
        disabled
      >Verzenden</button>
    </form>
  </div>
</body>
</html>