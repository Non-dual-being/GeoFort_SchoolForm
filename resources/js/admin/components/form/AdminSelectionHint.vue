<script setup lang="ts">
import {nextTick,onBeforeUnmount,onMounted,ref,useId} from "vue";
import {CircleHelp} from "lucide-vue-next";

const props=withDefaults(defineProps<{message:string;label?:string;variant?:"info"|"limit"|"unavailable";id?:string}>(),{label:"Meer uitleg",variant:"info",id:undefined});
const open=ref(false);
const root=ref<HTMLElement|null>(null);
const button=ref<HTMLButtonElement|null>(null);
const popover=ref<HTMLElement|null>(null);
const popoverStyle=ref<Record<string,string>>({});
let pointerInteracting=false;
const generatedId=`admin-selection-hint-${useId()}`;
const popoverId=props.id??generatedId;
const eventName="admin-selection-hint-open";

function setOpen(value:boolean):void{
  open.value=value;
  if(value){window.dispatchEvent(new CustomEvent(eventName,{detail:popoverId}));void nextTick(positionPopover);}
}
function positionPopover():void{if(!button.value)return;const rect=button.value.getBoundingClientRect();const width=Math.min(288,window.innerWidth-24);const left=Math.max(12,Math.min(window.innerWidth-width-12,rect.left+rect.width/2-width/2));const height=popover.value?.offsetHeight??0;const below=rect.bottom+6;const top=below+height>window.innerHeight-12?Math.max(12,rect.top-height-6):below;popoverStyle.value={width:`${width}px`,left:`${left}px`,top:`${top}px`};}
function toggle():void{setOpen(!open.value);}
function pointerDown():void{pointerInteracting=true;window.setTimeout(()=>{pointerInteracting=false;},0);}
function focus():void{if(!pointerInteracting)setOpen(true);}
function closeOnEscape(event:KeyboardEvent):void{if(event.key==="Escape"){open.value=false;(root.value?.querySelector("button") as HTMLButtonElement|null)?.focus();}}
function closeOutside(event:PointerEvent):void{if(open.value&&!root.value?.contains(event.target as Node))open.value=false;}
function closeForOther(event:Event):void{if((event as CustomEvent<string>).detail!==popoverId)open.value=false;}
function focusOut(event:FocusEvent):void{if(!root.value?.contains(event.relatedTarget as Node|null))open.value=false;}
function mouseLeave():void{if(!root.value?.matches(":focus-within"))open.value=false;}

onMounted(()=>{document.addEventListener("pointerdown",closeOutside);window.addEventListener(eventName,closeForOther);window.addEventListener("resize",positionPopover);});
onBeforeUnmount(()=>{document.removeEventListener("pointerdown",closeOutside);window.removeEventListener(eventName,closeForOther);window.removeEventListener("resize",positionPopover);});
</script>

<template>
  <span ref="root" class="admin-selection-hint" :class="`admin-selection-hint--${variant}`" @mouseenter="setOpen(true)" @mouseleave="mouseLeave" @focusout="focusOut" @keydown="closeOnEscape">
    <button ref="button" type="button" class="admin-selection-hint__button" :aria-expanded="open" :aria-controls="popoverId" :aria-label="label" @pointerdown="pointerDown" @click.stop="toggle" @focus="focus">
      <CircleHelp :size="18" aria-hidden="true"/>
    </button>
    <span v-show="open" ref="popover" :id="popoverId" class="admin-selection-hint__popover" :style="popoverStyle" role="tooltip">{{ message }}</span>
  </span>
</template>
