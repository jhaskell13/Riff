<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayoutV2 from '@/Layouts/AuthenticatedLayoutV2.vue';
import FeatureBlock from '@/Components/V2/FeatureBlock.vue';
import ContentBlock from '@/Components/V2/ContentBlock.vue';

const yourFeeds = ref([
    {
        author: 'Black Sabbath',
        profileImage: null,
        location: 'Hartford, CT',
        timestamp: '2 hours ago',
        text: 'Check out our latest single, Midnight Sun! This song is the first release from our upcoming debut album, Dark Matter!',
        media: {
            type: 'audio',
            title: 'Midnight Sun',
            duration: '3:42',
        },
        likes: 246,
        comments: 69,
    },
]);

const liveNowFeeds = ref([
    {
        title: 'Jazz Fusion Night',
        artist: 'The Blue Notes',
        viewers: 197,
    },
    {
        title: 'Ozzy Tribute | RIP Prince of Darkness!!',
        artist: 'Bango Kablooie',
        viewers: 666,
    },
]);
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayoutV2>
        <div class="p-4 md:p-8">
            <FeatureBlock>
                <input
                    class="w-full min-w-0 bg-[#343B53] border-0 rounded-full text-gray-200"
                    type="text"
                    placeholder="Search bands, music, venues..."    
                >
            </FeatureBlock>
            <div class="flex gap-4 md:gap-8 mt-4 md:mt-8">
                <!-- Your Feed -->
                <FeatureBlock class="w-full">
                    <template #header>Your Feed</template>
                    <ContentBlock
                        v-for="(feed, index) in yourFeeds"
                        :key="index"
                    >
                        <div class="text-gray-200">
                            <div class="flex">
                                <span class="w-[50px] h-[50px] rounded-full bg-black"></span>
                                <div class="ml-2 flex flex-col justify-evenly">
                                    <div class="font-bold text-lg">{{ feed.author }}</div>
                                    <div class="text-xs text-gray-400">{{ feed.location }}&nbsp;&bull;&nbsp;{{ feed.timestamp }}</div>
                                </div>
                            </div>
                            <div class="mt-4">
                                <p>
                                    {{ feed.text }}
                                </p>

                                <!-- Uploaded Content Preview -->
                                 <div
                                    v-if="feed.media"
                                    class="flex items-center mt-4 p-4 bg-[#242B3D] rounded-xl"
                                >
                                    <button class="bg-[#ff6b6b] w-[40px] h-[40px] rounded-full flex items-center justify-center">
                                            <div class="text-[28px]">&#9658;</div>
                                    </button>
                                    <div class="ml-2">
                                        <div class="font-bold text-lg">
                                            {{ feed.media.title }}
                                        </div>
                                        <div class="text-xs text-gray-400">{{ feed.media.duration }}</div>
                                    </div>
                                 </div>

                                 <hr class="my-4 border-gray-500" />

                                 <div class="flex gap-4 text-xs [@media(min-width:350px)]:text-sm">
                                    <button>
                                        {{ feed.likes }}&nbsp;Likes
                                    </button>
                                    <button>
                                        {{ feed.comments }}&nbsp;Comments
                                    </button>
                                    <button>Share</button>
                                    <button>Download</button>
                                 </div>
                            </div>
                        </div>
                    </ContentBlock>
                </FeatureBlock>

                <!-- Sidebar Content -->
                <FeatureBlock class="hidden [@media(min-width:640px)]:block w-[280px] min-w-0">
                    <template #header>Live Now</template>
                    <ContentBlock
                        class="max-w-[200px] min-w-0 mt-2 bg-[rgb(239,68,68,0.2)] shadow-[inset_0_0_0_0.3px_rgb(239,68,68,1)] leading-none"
                        v-for="feed in liveNowFeeds"
                        :key="feed.title"    
                    >
                        <Link :href="route('streams')">
                        <div class="border border-red-700 bg-red-500 w-max px-2 rounded-[16px] font-bold text-sm">
                            <span class="text-red-700">&#x25CF;</span>&nbsp;LIVE
                        </div>
                        <div class="leading-tight font-bold text-lg overflow-hidden whitespace-nowrap text-ellipsis">
                            {{ feed.title }}
                        </div>
                        <div class="text-sm">
                            {{ feed.artist }}
                        </div>
                        <div>
                            {{ feed.viewers }}&nbsp;&#x1F441;
                        </div>
                        </Link>
                    </ContentBlock>
                </FeatureBlock>
            </div>
        </div>
    </AuthenticatedLayoutV2>
</template>
