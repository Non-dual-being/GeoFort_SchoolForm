<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import GlobeRating from "./GlobeRating.vue";
import { wordCount, type Satisfaction } from "./policy";

type Survey = { state: string; visitDate?: string; modules?: { key: string; label: string }[] };
const survey = ref<Survey | null>(null);
const satisfaction = ref<Satisfaction | null>(null);
const favorite = ref("");
const tip = ref("");
const busy = ref(true);
const error = ref("");
const withdrawnConfirm = ref(false);
let csrf = "";
const token = new URLSearchParams(window.location.hash.slice(1)).get("token") ?? "";
const words = computed(() => wordCount(tip.value));
const date = computed(() => survey.value?.visitDate ? new Date(survey.value.visitDate + "T12:00:00").toLocaleDateString("nl-NL", { day: "numeric", month: "long", year: "numeric" }) : "");
const invalidTip = computed(() => words.value > 50 || [...tip.value].length > 1000);

async function request(action: string) {
  const response = await fetch("/api/visit-survey.php", { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    body: JSON.stringify({ token, action, satisfaction: satisfaction.value, favoriteModule: favorite.value || null, tip: tip.value }) });
  const result = await response.json();
  if (!response.ok || !result.ok) throw new Error(result.message ?? "Opslaan is niet gelukt. Probeer het straks opnieuw.");
  survey.value = result.data;
}
async function act(action: string) {
  busy.value = true; error.value = "";
  try { await request(action); } catch (e) { error.value = e instanceof Error ? e.message : "Er ging iets mis."; }
  finally { busy.value = false; }
}
onMounted(async () => {
  try {
    if (!/^[a-f0-9]{64}$/.test(token)) throw new Error("Open de persoonlijke evaluatielink uit de e-mail van GeoFort.");
    const response = await fetch("/api/visit-survey.php", { credentials: "same-origin" });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error("De evaluatie kon niet worden geopend.");
    csrf = result.csrfToken;
    await request("open");
  } catch (e) { error.value = e instanceof Error ? e.message : "De evaluatie kon niet worden geopend."; }
  finally { busy.value = false; }
});
</script>

<template>
  <div class="survey-page">
    <header><a href="https://www.geofort.nl/" rel="noreferrer">Geo<span>Fort</span></a><span>ONDERWIJS</span></header>
    <main>
      <p class="eyebrow">JULLIE ERVARING TELT</p>
      <h1>Hoe was jullie bezoek?</h1>
      <p class="intro">Met drie korte vragen help je ons het onderwijsaanbod te verbeteren.</p>
      <p v-if="date" class="visit-date">Jullie bezoek van {{ date }}</p>
      <p v-if="error" role="alert" class="error">{{ error }}</p>
      <p v-if="busy" role="status">Even geduld…</p>
      <form v-if="survey?.state === 'open'" @submit.prevent="act('respond')">
        <section><GlobeRating v-model="satisfaction" :disabled="busy" /></section>
        <section>
          <fieldset>
            <legend>Welke module viel bij jullie groep het meest in de smaak?</legend>
            <p class="hint">Kies één module uit jullie rooster.</p>
            <label v-for="module in survey.modules" :key="module.key" class="module-choice" :class="{ selected: favorite === module.key }">
              <input v-model="favorite" type="radio" name="favorite" :value="module.key" :disabled="busy">{{ module.label }}
            </label>
            <label class="module-choice" :class="{ selected: favorite === '' }"><input v-model="favorite" type="radio" name="favorite" value="" :disabled="busy">Geen voorkeur / kan ik niet beoordelen</label>
          </fieldset>
        </section>
        <section>
          <label class="question" for="visit-tip">Heb je een tip voor ons? <span class="optional">Niet verplicht</span></label>
          <p id="tip-help" class="hint">Maximaal 50 woorden. Deel geen namen of andere gegevens van leerlingen.</p>
          <textarea id="visit-tip" v-model="tip" rows="4" :disabled="busy" aria-describedby="tip-help tip-count" :aria-invalid="invalidTip" />
          <p id="tip-count" class="counter" :class="{ invalid: invalidTip }" aria-live="polite">{{ words }} / 50 woorden<span v-if="[...tip].length > 1000"> · maximaal 1.000 tekens</span></p>
        </section>
        <p class="privacy">Je antwoord is gekoppeld aan jullie bezoek. Alleen het onderwijsteam gebruikt de resultaten om het aanbod te verbeteren. Vul de evaluatie namens de school in.</p>
        <button class="primary" :disabled="busy || satisfaction === null || invalidTip">Verstuur mijn ervaring</button>
      </form>
      <section v-else-if="survey?.state === 'answered'" class="message"><h2>Dank je wel!</h2><p>Jullie ervaring is opgeslagen. Daarmee help je ons toekomstige bezoeken nog beter te maken.</p></section>
      <section v-else-if="survey?.state === 'withdrawn'" class="message"><h2>Toestemming ingetrokken</h2><p>Je ontvangt geen evaluatie voor dit bezoek meer. Een eventueel eerder antwoord is verwijderd.</p></section>
      <section v-else-if="survey?.state === 'expired' || survey?.state === 'unavailable'" class="message"><h2>Deze evaluatie is niet meer beschikbaar</h2><p>De link is verlopen of de bezoekgegevens zijn gewijzigd. Je kunt je toestemming hieronder nog intrekken.</p></section>
      <div v-if="survey && survey.state !== 'withdrawn'" class="withdrawal">
        <button v-if="!withdrawnConfirm" type="button" class="text-button" @click="withdrawnConfirm = true">Toestemming intrekken</button>
        <div v-else><p>Wil je je toestemming intrekken? We verwijderen dan ook een eventueel eerder antwoord voor dit bezoek.</p><button type="button" :disabled="busy" @click="act('withdraw')">Ja, intrekken</button> <button type="button" @click="withdrawnConfirm = false">Annuleren</button></div>
      </div>
    </main>
    <footer>GeoFort Onderwijs · <a href="mailto:onderwijs@geofort.nl">onderwijs@geofort.nl</a></footer>
  </div>
</template>

<style>
body { margin: 0; background: #edf3f7; }
</style>
<style scoped>
.survey-page { font-family: system-ui, -apple-system, sans-serif; color: #102454; font-size: 1rem; line-height: 1.55; }
header { display: flex; justify-content: space-between; align-items: center; max-width: 720px; margin: auto; padding: 1.5rem; }
header a { font-weight: 800; font-size: 1.75rem; text-decoration: none; color: #102454; } header a span { color: #b42233; } header>span { font-size: .7rem; letter-spacing: .15em; }
main { max-width: 660px; margin: 0 auto; padding: 2.3rem; background: white; border: 1px solid #dbe4ef; border-radius: 1.3rem; box-shadow: 0 8px 30px #10245409; }
h1 { font-size: clamp(1.8rem,4vw,2.35rem); line-height: 1.2; margin: .4rem 0 .8rem; } h2 { margin-top: 0; }
.eyebrow { font-size: .72rem; letter-spacing: .14em; font-weight: 700; color: #3a6b81; margin: 0; }
.intro,.hint,.privacy,.optional { color: #55667d; } .visit-date { display: inline-block; background: #eef5fb; border-radius: .5rem; padding: .5rem .8rem; font-size: .9rem; margin: .3rem 0 1rem; }
section { padding: 1.6rem 0; border-top: 1px solid #e1e8f0; } fieldset { border: 0; margin: 0; padding: 0; min-width: 0; } legend,.question { font-size: 1.15rem; font-weight: 700; }
.hint { font-size: .9rem; margin: .6rem 0 1rem; }.optional { display: block; font-size: .8rem; font-weight: 400; }
.module-choice { display: flex; align-items: center; gap: .8rem; cursor: pointer; border: 1px solid #d6e1ee; border-radius: .6rem; padding: .85rem; margin-top: .6rem; }
.module-choice.selected { border-color: #426b9e; background: #f0f6fc; } input { accent-color: #173c71; width: 1.15rem; height: 1.15rem; flex: 0 0 auto; }
textarea { width: 100%; box-sizing: border-box; padding: .85rem; font: inherit; resize: vertical; border: 1px solid #b6c7db; border-radius: .6rem; color: inherit; }
button { font: inherit; cursor: pointer; border-radius: .55rem; padding: .6rem 1rem; color: #102454; border: 1px solid #c6d3e3; background: white; } button:disabled { opacity: .55; cursor: default; }
.primary { background: #123467; color: white; border-color: #123467; font-weight: 650; width: 100%; padding: 1rem; margin-top: .6rem; }
.counter { text-align: right; font-size: .8rem; margin: .3rem 0 0; }.invalid,.error { color: #9d1730; }.error { background: #fff2f4; padding: 1rem; border-radius: .5rem; }
.privacy { font-size: .8rem; }.message { margin-top: 1.5rem; }.withdrawal { margin-top: 1.5rem; font-size: .8rem; }.text-button { background: none; border: 0; padding: 0; text-decoration: underline; }
footer { text-align: center; font-size: .8rem; color: #55667d; padding: 2rem 1rem; }footer a { color: inherit; }
:focus-visible { outline: 3px solid #80a8dc; outline-offset: 3px; }
@media (max-width: 600px) { main { padding: 1.5rem 1.1rem; margin: 0 .75rem; } }
</style>
