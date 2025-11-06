<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import LiveChat from '@/Components/LiveChat.vue'

const props = defineProps({
  stream: Object,
})

const previewVideo = ref(null)
const isStreaming = ref(false)
const viewerCount = ref(0)
const chatCount = ref(0)
const reactionCount = ref(0)

let mediaRecorder = null
let stream = null
let echo = null

const startBroadcast = async () => {
  try {
    // Get user media with high quality audio
    stream = await navigator.mediaDevices.getUserMedia({
      video: { 
        width: { ideal: 1280 }, 
        height: { ideal: 720 },
        frameRate: { ideal: 30 }
      },
      audio: { 
        sampleRate: 48000,
        channelCount: 2,
        echoCancellation: false,
        noiseSuppression: false
      }
    })

    previewVideo.value.srcObject = stream

    // Create MediaRecorder with optimized settings
    mediaRecorder = new MediaRecorder(stream, {
      mimeType: 'video/webm;codecs=vp8,opus',
      videoBitsPerSecond: 2500000, // 2.5 Mbps
      audioBitsPerSecond: 320000,  // 320 kbps - high quality audio
    })

    // Handle data chunks
    mediaRecorder.ondataavailable = (event) => {
      if (event.data.size > 0) {
        const reader = new FileReader()
        reader.onload = () => {
          const base64Data = reader.result.split(',')[1]
          
          // Send via axios (not through WebSocket due to size limits)
          axios.post(`/api/streams/${props.stream.id}/data`, {
            chunk: base64Data,
            type: 'video'
          }).catch(error => {
            console.error('Error sending stream data:', error)
          })
        }
        reader.readAsDataURL(event.data)
      }
    }

    // Start recording in small chunks for low latency
    mediaRecorder.start(200) // 200ms chunks
    isStreaming.value = true

    // Notify server
    await axios.post(`/api/streams/${props.stream.id}/start`)

    setupReverbListeners()

  } catch (error) {
    console.error('Error starting broadcast:', error)
    alert('Could not access camera/microphone. Please check permissions.')
  }
}

const stopBroadcast = async () => {
  if (mediaRecorder && mediaRecorder.state !== 'inactive') {
    mediaRecorder.stop()
  }
  if (stream) {
    stream.getTracks().forEach(track => track.stop())
  }
  isStreaming.value = false

  await axios.post(`/api/streams/${props.stream.id}/stop`)
}

const setupReverbListeners = () => {
  echo = window.Echo.channel(`stream.${props.stream.id}`)
    .listen('.viewer.joined', (e) => {
      viewerCount.value = e.count
    })
    .listen('.viewer.left', (e) => {
      viewerCount.value = e.count
    })

  // Listen to reactions for stats
  window.Echo.channel(`stream.${props.stream.id}.reactions`)
    .listen('.stream.reaction', (e) => {
      reactionCount.value++
    })
}

onMounted(() => {
  // Get initial viewer count
  axios.get(`/api/streams/${props.stream.id}/stats`)
    .then(response => {
      viewerCount.value = response.data.viewer_count
    })
})

onUnmounted(() => {
  stopBroadcast()
  if (echo) {
    window.Echo.leaveChannel(`stream.${props.stream.id}`)
  }
})
</script>

<template>
  <div class="max-w-6xl mx-auto p-6">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Video Preview & Controls -->
      <div class="lg:col-span-2">
        <div class="bg-black rounded-lg overflow-hidden">
          <video 
            ref="previewVideo" 
            autoplay 
            muted 
            class="w-full h-64 object-cover"
          />
        </div>
        
        <div class="mt-4 flex gap-4">
          <button 
            @click="startBroadcast" 
            :disabled="isStreaming"
            class="bg-red-500 hover:bg-red-600 disabled:opacity-50 text-white px-6 py-2 rounded-lg font-semibold"
          >
            {{ isStreaming ? '🔴 LIVE' : 'Go Live' }}
          </button>
          
          <button 
            @click="stopBroadcast" 
            :disabled="!isStreaming"
            class="bg-gray-500 hover:bg-gray-600 disabled:opacity-50 text-white px-6 py-2 rounded-lg"
          >
            Stop Stream
          </button>
        </div>

        <!-- Stream Stats -->
        <div class="mt-4 grid grid-cols-3 gap-4">
          <div class="bg-gray-100 p-4 rounded-lg text-center">
            <div class="text-2xl font-bold">{{ viewerCount }}</div>
            <div class="text-sm text-gray-600">Viewers</div>
          </div>
          <div class="bg-gray-100 p-4 rounded-lg text-center">
            <div class="text-2xl font-bold">{{ chatCount }}</div>
            <div class="text-sm text-gray-600">Messages</div>
          </div>
          <div class="bg-gray-100 p-4 rounded-lg text-center">
            <div class="text-2xl font-bold">{{ reactionCount }}</div>
            <div class="text-sm text-gray-600">Reactions</div>
          </div>
        </div>
      </div>

      <!-- Live Chat -->
      <div class="lg:col-span-1">
        <LiveChat :stream-id="stream.id" @message-sent="chatCount++" />
      </div>
    </div>
  </div>
</template>