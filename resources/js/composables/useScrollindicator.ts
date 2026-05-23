// resources/js/composables/useScrollIndicator.ts
import { onMounted, onBeforeUnmount, unref, watch, type Ref, isRef } from 'vue'

type ScrollTarget = Window | HTMLElement
type MaybeRef<T> = T | Ref<T | null | undefined> /**T or Reft T that can ben null of undefined, union type */

export function useScrollIndicator(
  target: MaybeRef<ScrollTarget> = window, /**T hier scroll target kan aangeroepen worden met een plain value T, hoef niet een ref te tzijn */
  { fadeOutMs = 800 } = {} // Iets langere fade-out is vaak mooier
) {
  let rafId: number | null = null
  let timeoutId: number | null = null
  let attachedTo: ScrollTarget | null = null

  // Helper om de CSS variabele te zetten
  const setAlpha = (value: string) => {
    document.documentElement.style.setProperty('--sb-alpha', value)
  }

  const setScrolling = (value: boolean) => {
    document.documentElement.classList.toggle("is-scrolling", value);
  };


  const onScroll = () => {
    setAlpha("1");
    setScrolling(true);

    if (rafId) cancelAnimationFrame(rafId);

    rafId = requestAnimationFrame(() => {
      if (timeoutId) clearTimeout(timeoutId);

      timeoutId = window.setTimeout(() => {
        setAlpha("0");
        setScrolling(false);
      }, fadeOutMs);
    });
  };
  // Als we hoveren over het venster, willen we ook scrollbars zien
  const onMouseEnter = () => setAlpha('0.4')
  const onMouseLeave = () => setAlpha('0') // Of laat hem staan, jouw keus

  const attach = (el: ScrollTarget) => {
    if (attachedTo) return
    
    // Scroll event
    el.addEventListener('scroll', onScroll as EventListener, { passive: true })
    
    // Als we window gebruiken, kunnen we ook muis-activiteit volgen voor hover-effect
    if (el === window) {
        document.documentElement.addEventListener('mousemove', onMouseEnter)
        document.documentElement.addEventListener('mouseleave', onMouseLeave)
    }

    attachedTo = el
    // Startwaarde: onzichtbaar
    setAlpha('0') 
  }

  const detach = () => {
    if (!attachedTo) return
    attachedTo.removeEventListener('scroll', onScroll as EventListener)
    if (attachedTo === window) {
        document.documentElement.removeEventListener('mousemove', onMouseEnter)
    }
    attachedTo = null
  }

  onMounted(() => {
    const el = unref(target)
    /**
     * if target is a actueel ref its returns its .value prop
     * if target is a direct elelement, it returns the element
     */

    // Startwaarde resetten
    setAlpha('0')
    
    if (el) attach(el)

    /**
     * prev code: `typeof (target as any)?.value !== 'undefined`
     * this check if the targets is a ref en if so the wathc detects changens in its value
     * however us the inbuild isRef
     */

    /**
     * unref is the getter, means its triggers every time the refernetial value chagens
     * flush post makes sure that this operatiom is done when the DOM has done als its updates
     */

    
    if (isRef(target)) {
      watch(() => unref(target), (newEl) => {
        detach()
        if (newEl) attach(newEl)
      }, { flush: 'post' })
    }
    if (!attachedTo) attach(window)
  })

  onBeforeUnmount(() => {
    detach();

    if (rafId) cancelAnimationFrame(rafId);
    if (timeoutId) clearTimeout(timeoutId);

    setScrolling(false);
   })
}