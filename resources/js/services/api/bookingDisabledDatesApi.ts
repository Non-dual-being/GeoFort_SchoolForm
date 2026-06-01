import type { fullDatesInfo } from "../../types/booking/BookingDateType";
import { getApiData } from "../http/apiClient";

let disabledDatesPromise: Promise<fullDatesInfo> | null = null;
let disabledDatesCache: fullDatesInfo | null = null;
let disabledDatesCacheTime = 0;

const CACHE_TTL_MS = 5 * 60 * 1000; // 5 min

export async function fetchDisabledDates(): Promise<fullDatesInfo> {
    
    const now = Date.now();

    if (disabledDatesCache && ((now - disabledDatesCacheTime) < CACHE_TTL_MS))
        return disabledDatesCache;

    if (disabledDatesPromise) return disabledDatesPromise;

    disabledDatesPromise = getApiData<fullDatesInfo>("api/getDisabledDates.php", {
        method: "GET",
    }).then((data) => {
        disabledDatesCache= data;
        disabledDatesCacheTime = Date.now();
        return data;
    }).finally(() => {
        disabledDatesPromise = null;
    });

    return disabledDatesPromise;

}


/*
 * Eerste call flow:
 *
 * 1. disabledDatesCache is nog null, dus de cache-check wordt overgeslagen.
 *
 * 2. disabledDatesPromise is ook nog null, dus er is nog geen request bezig.
 *
 * 3. getApiData(...) start de API-request en geeft meteen een Promise terug.
 *    Op dit moment is de Promise nog pending, want PHP heeft nog geen data
 *    teruggestuurd.
 *
 * 4. Die pending Promise wordt opgeslagen in disabledDatesPromise.
 *
 * 5. Daarna returnt fetchDisabledDates() meteen disabledDatesPromise.
 *    De caller krijgt dus direct een pending Promise terug.
 *
 * 6. Wanneer PHP later de data terugstuurt, wordt de .then(...) uitgevoerd.
 *    In de .then(...) wordt:
 *
 *      - disabledDatesCache gevuld met de data
 *      - disabledDatesCacheTime bijgewerkt
 *      - return data gedaan
 *
 *    Die return data is belangrijk, want daarmee wordt de data doorgegeven
 *    naar de volgende stap in de Promise-chain.
 *
 * 7. Daarna wordt .finally(...) uitgevoerd.
 *    In de finally wordt alleen disabledDatesPromise op null gezet.
 *
 *    Dit betekent niet dat de data null wordt.
 *    Dit betekent alleen: er is geen lopende request meer.
 *
 * 8. finally geeft de data automatisch door.
 *    Je hoeft in finally dus niet opnieuw return data te doen.
 *
 * 9. Daarna wordt de Promise die de caller heeft fulfilled met de data.
 *
 * Eindstatus na een succesvolle eerste call:
 *
 *      disabledDatesPromise = null
 *      disabledDatesCache = data
 *      disabledDatesCacheTime = tijdstip van opslaan
 *
 * Belangrijk:
 *
 * disabledDatesPromise = null zet alleen de globale Promise-referentie leeg.
 * De Promise die al aan de caller is teruggegeven blijft gewoon bestaan
 * en levert nog steeds de data op.
 */