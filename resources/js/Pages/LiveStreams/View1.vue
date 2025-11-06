<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
// import LiveChat from '@/Components/LiveChat.vue'

const props = defineProps({
  stream: Object,
})

const videoPlayer = ref(null)
const viewerCount = ref(0)
const activeReactions = ref([])
const reactions = ['❤️', '👏', '🔥', '😍', '🤘', '🎵', '🎸', '🥁']

let mediaSource = null
let sourceBuffer = null
let echo = null

const initializePlayer = () => {
  if ('MediaSource' in window) {
    console.log('media source');
    
    mediaSource = new MediaSource()
    videoPlayer.value.src = URL.createObjectURL(mediaSource)
    
    mediaSource.addEventListener('sourceopen', () => {
      try {
        sourceBuffer = mediaSource.addSourceBuffer('video/webm; codecs="vp8,opus"')
        
        sourceBuffer.addEventListener('error', (e) => {
          console.error('SourceBuffer error:', e)
        })
      } catch (error) {
        console.error('Error creating source buffer:', error)
      }
    })
  }
}

const handleStreamData = (data) => {
  if (sourceBuffer && !sourceBuffer.updating && mediaSource.readyState === 'open') {
    try {
    console.log('handle stream');
    
      // Convert base64 to binary
      const binaryString = atob(data.chunk)
      const bytes = new Uint8Array(binaryString.length)
      
      for (let i = 0; i < binaryString.length; i++) {
        bytes[i] = binaryString.charCodeAt(i)
      }
      
      sourceBuffer.appendBuffer(bytes)
    } catch (error) {
      console.error('Error handling stream data:', error)
    }
  }
  console.log('no handle');
  
}

const sendReaction = async (emoji) => {
//   try {
//     await axios.post(`/api/streams/${props.stream.id}/reaction`, {
//       reaction: emoji,
//       x: Math.random() * 80 + 10, // 10-90% from left
//       y: Math.random() * 60 + 20  // 20-80% from top
//     })
//   } catch (error) {
//     console.error('Error sending reaction:', error)
//   }
}

const showReaction = (data) => {
  const reaction = {
    id: Date.now() + Math.random(),
    reaction: data.reaction,
    position: data.position,
  }
  
  activeReactions.value.push(reaction)
  
  // Remove after animation
  setTimeout(() => {
    const index = activeReactions.value.findIndex(r => r.id === reaction.id)
    if (index > -1) {
      activeReactions.value.splice(index, 1)
    }
  }, 3000)
}

const setupReverbListeners = () => {
  // Listen for stream data
  echo = window.Echo.channel(`stream.${props.stream.id}`)
    .listen('.stream.data', handleStreamData)

  // Listen for reactions
  window.Echo.channel(`stream.${props.stream.id}.reactions`)
    .listen('.stream.reaction', showReaction)

  // Listen for viewer count updates
  window.Echo.channel(`stream.${props.stream.id}`)
    .listen('.viewer.count', (e) => {
      viewerCount.value = e.count
    })
}

onMounted(() => {
  initializePlayer()
  setupReverbListeners()
  
  // Join as viewer
//   axios.post(`/streams/${props.stream.id}/join`)
//     .then(response => {
//       viewerCount.value = response.data.viewer_count
//     })
})

onUnmounted(() => {
  // Leave as viewer
  axios.post(`/api/streams/${props.stream.id}/leave`)
  
  if (echo) {
    window.Echo.leaveChannel(`stream.${props.stream.id}`)
    window.Echo.leaveChannel(`stream.${props.stream.id}.reactions`)
  }
})
</script>

<template>
  <div class="max-w-6xl mx-auto p-6">
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
      <!-- Video Player -->
      <div class="lg:col-span-3">
        <div class="relative bg-black rounded-lg overflow-hidden">
          <video 
            ref="videoPlayer" 
            autoplay 
            controls
            class="w-full h-96 object-cover"
          />
          
          <!-- Reaction Overlay -->
          <div class="absolute inset-0 pointer-events-none overflow-hidden">
            <div 
              v-for="reaction in activeReactions" 
              :key="reaction.id"
              :style="{ 
                left: reaction.position.x + '%', 
                top: reaction.position.y + '%',
                transform: 'translate(-50%, -50%)'
              }"
              class="absolute text-3xl animate-ping"
            >
              {{ reaction.reaction }}
            </div>
          </div>
        </div>

        <!-- Stream Info -->
        <div class="mt-4">
          <h1 class="text-2xl font-bold">{{ stream.title }}</h1>
          <p class="text-gray-600 mt-2">{{ stream.description }}</p>
          <div class="flex items-center gap-4 mt-4">
            <span class="bg-red-500 text-white px-3 py-1 rounded-full text-sm font-semibold">
              🔴 LIVE
            </span>
            <span class="text-gray-600">{{ viewerCount }} viewers</span>
          </div>
        </div>

        <!-- Reaction Buttons -->
        <div class="mt-6 flex gap-2">
          <button 
            v-for="emoji in reactions"
            :key="emoji"
            @click="sendReaction(emoji)"
            class="text-2xl p-3 hover:bg-gray-100 rounded-lg transition-colors"
          >
            {{ emoji }}
          </button>
        </div>
      </div>

      <!-- Chat Sidebar -->
      <div class="lg:col-span-1">
        <!-- <LiveChat :stream-id="stream.id" /> -->
      </div>
    </div>
  </div>
</template>
