<template>
    <div class="p-4 bg-[#232A44] rounded-xl shadow-[inset_0_0_0_0.3px_#6b7280]">
        <header
            class="font-bold text-2xl text-[#ff6b6b]"
            v-if="$slots.header"
        >
            <slot name="header" />
        </header>
        <main :class="$slots.header ? 'mt-2': ''">
            <slot />
        </main>
    </div>
</template>