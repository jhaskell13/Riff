<!-- resources/js/Pages/Streams/Watch.vue -->
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
          
          <!-- Debug Info -->
          <div class="absolute top-4 left-4 bg-black bg-opacity-75 text-white p-2 rounded text-sm">
            <div>Connection: {{ connectionStatus }}</div>
            <div>MediaSource: {{ mediaSourceState }}</div>
            <div>Buffer: {{ bufferState }}</div>
            <div>Chunks Received: {{ chunksReceived }}</div>
            <div>Last Chunk: {{ lastChunkTime }}</div>
          </div>
          
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

        <!-- Debug Controls -->
        <div class="mt-4 p-4 bg-gray-100 rounded-lg">
          <h3 class="font-semibold mb-2">Debug Controls</h3>
          <div class="flex gap-2">
            <button 
              @click="reinitializePlayer"
              class="bg-blue-500 text-white px-3 py-1 rounded text-sm"
            >
              Reinitialize Player
            </button>
            <button 
              @click="clearBuffer"
              class="bg-yellow-500 text-white px-3 py-1 rounded text-sm"
            >
              Clear Buffer
            </button>
            <button 
              @click="toggleDebugMode"
              class="bg-purple-500 text-white px-3 py-1 rounded text-sm"
            >
              {{ debugMode ? 'Hide' : 'Show' }} Debug
            </button>
          </div>
          
          <!-- <div v-if="debugMode" class="mt-4 text-sm">
            <div class="bg-white p-2 rounded border max-h-32 overflow-y-auto">
              <div v-for="log in debugLogs" :key="log.id" class="text-xs mb-1">
                <span class="text-gray-500">{{ log.time }}</span>: {{ log.message }}
              </div>
            </div>
          </div> -->
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

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
// import LiveChat from '@/Components/LiveChat.vue'
import Echo from 'laravel-echo';

const props = defineProps({
  stream: Object,
})

const videoPlayer = ref(null)
const viewerCount = ref(0)
const activeReactions = ref([])
const reactions = ['❤️', '👏', '🔥', '😍', '🤘', '🎵', '🎸', '🥁']

// Debug states
const connectionStatus = ref('Disconnected')
const mediaSourceState = ref('Not initialized')
const bufferState = ref('Not ready')
const chunksReceived = ref(0)
const lastChunkTime = ref('Never')
const debugMode = ref(true)
const debugLogs = ref([])

let mediaSource = null
let sourceBuffer = null
let echo = null
let pendingChunks = []

const addDebugLog = (message) => {
  debugLogs.value.unshift({
    id: Date.now(),
    time: new Date().toLocaleTimeString(),
    message: message
  })
  
  // Keep only last 50 logs
  if (debugLogs.value.length > 50) {
    debugLogs.value = debugLogs.value.slice(0, 50)
  }
  
  console.log('[Stream Debug]', message)
}

const initializePlayer = () => {
  addDebugLog('Initializing MediaSource player.')
  
  if (!('MediaSource' in window)) {
    addDebugLog('ERROR: MediaSource not supported in this browser')
    mediaSourceState.value = 'Not supported'
    return
  }

  try {
    mediaSource = new MediaSource()
    videoPlayer.value.src = URL.createObjectURL(mediaSource)
    mediaSourceState.value = 'Created'
    
    mediaSource.addEventListener('sourceopen', () => {
      addDebugLog('MediaSource opened')
      mediaSourceState.value = 'Open'
      
      try {
        // Try different codecs if the first one doesn't work
        const codecs = [
          'video/webm; codecs="vp8,opus"',
          'video/webm; codecs="vp9,opus"',
          'video/webm; codecs="vp8"',
          'video/mp4; codecs="avc1.42E01E,mp4a.40.2"'
        ]
        
        let codecSupported = false
        for (const codec of codecs) {
          if (MediaSource.isTypeSupported(codec)) {
            addDebugLog(`Using codec: ${codec}`)
            sourceBuffer = mediaSource.addSourceBuffer(codec)
            codecSupported = true
            break
          }
        }
        
        if (!codecSupported) {
          addDebugLog('ERROR: No supported codecs found')
          bufferState.value = 'No codec support'
          return
        }
        
        bufferState.value = 'Ready'
        
        sourceBuffer.addEventListener('updateend', () => {
          // Process any pending chunks
          if (pendingChunks.length > 0 && !sourceBuffer.updating) {
            const nextChunk = pendingChunks.shift()
            try {
              sourceBuffer.appendBuffer(nextChunk)
            } catch (error) {
              addDebugLog(`Error appending pending chunk: ${error.message}`)
            }
          }
        })
        
        sourceBuffer.addEventListener('error', (e) => {
          addDebugLog(`SourceBuffer error`)
          console.log(e);
          
          bufferState.value = 'Error'
        })
        
        addDebugLog('SourceBuffer created successfully')
        
      } catch (error) {
        addDebugLog(`Error creating SourceBuffer: ${error.message}`)
        bufferState.value = 'Creation failed'
      }
    })
    
    mediaSource.addEventListener('sourceended', () => {
      addDebugLog('MediaSource ended')
      mediaSourceState.value = 'Ended'
    })
    
    mediaSource.addEventListener('error', (e) => {
      addDebugLog(`MediaSource error: ${e}`)
      mediaSourceState.value = 'Error'
    })
    
  } catch (error) {
    addDebugLog(`Error initializing MediaSource: ${error.message}`)
    mediaSourceState.value = 'Failed'
  }
}

const handleStreamData = (data) => {
    console.log('stream data: ', data);
    
  chunksReceived.value++
  lastChunkTime.value = new Date().toLocaleTimeString()
  addDebugLog(`Received chunk #${chunksReceived.value}, size: ${data.chunk.length} chars`)
  
  if (!sourceBuffer) {
    addDebugLog('ERROR: SourceBuffer not ready')
    return
  }
  
  if (!mediaSource || mediaSource.readyState !== 'open') {
    addDebugLog(`ERROR: MediaSource not ready (state: ${mediaSource?.readyState})`)
    return
  }
  
  try {
    // Convert base64 to binary
    const binaryString = atob(data.chunk)
    const bytes = new Uint8Array(binaryString.length)
    
    for (let i = 0; i < binaryString.length; i++) {
      bytes[i] = binaryString.charCodeAt(i)
    }
    
    addDebugLog(`Converted chunk to ${bytes.length} bytes`)
    
    if (sourceBuffer.updating) {
      // Queue the chunk if buffer is still updating
      pendingChunks.push(bytes)
      addDebugLog('Queued chunk (buffer updating)')
    } else {
      sourceBuffer.appendBuffer(bytes)
      addDebugLog('Appended chunk to buffer')
    }
    
  } catch (error) {
    addDebugLog(`Error handling stream data: ${error.message}`)
  }
}

const reinitializePlayer = () => {
  addDebugLog('Reinitializing player.')
  
  // Clean up old instances
  if (mediaSource) {
    try {
      if (mediaSource.readyState === 'open') {
        mediaSource.endOfStream()
      }
    } catch (e) {
      addDebugLog(`Error ending old MediaSource: ${e.message}`)
    }
  }
  
  // Reset states
  mediaSource = null
  sourceBuffer = null
  pendingChunks = []
  chunksReceived.value = 0
  
  // Reinitialize
  setTimeout(initializePlayer, 100)
}

const clearBuffer = () => {
  if (sourceBuffer && !sourceBuffer.updating) {
    try {
      sourceBuffer.remove(0, sourceBuffer.buffered.end(0))
      addDebugLog('Buffer cleared')
    } catch (error) {
      addDebugLog(`Error clearing buffer: ${error.message}`)
    }
  }
}

const toggleDebugMode = () => {
  debugMode.value = !debugMode.value
}

const sendReaction = async (emoji) => {
  try {
    await axios.post(`/streams/${props.stream.id}/reaction`, {
      reaction: emoji,
      x: Math.random() * 80 + 10,
      y: Math.random() * 60 + 20
    })
  } catch (error) {
    addDebugLog(`Error sending reaction: ${error.message}`)
  }
}

const showReaction = (data) => {
  const reaction = {
    id: Date.now() + Math.random(),
    reaction: data.reaction,
    position: data.position,
  }
  
  activeReactions.value.push(reaction)
  
  setTimeout(() => {
    const index = activeReactions.value.findIndex(r => r.id === reaction.id)
    if (index > -1) {
      activeReactions.value.splice(index, 1)
    }
  }, 3000)
}


const setupReverbListeners = () => {
  addDebugLog('Setting up Reverb listeners.')
  
  try { 
    // Listen for stream data
    echo = window.Echo.channel(`livestream.${props.stream.id}`)
        .listen('LiveStreamStarted', (data) => {
            addDebugLog('Stream started event received')
        })
        .listen('LiveStreamDataSent', (data) => {
            addDebugLog('Received stream data event')
            handleStreamData(data)
        })
        // .listen('.livestream.stopped', (data) => {
        //     addDebugLog('Stream stopped event received')
        // })

    // Listen for reactions
    // window.Echo.channel(`livestream.${props.stream.id}.reactions`)
    //   .listen('.livestream.reaction', showReaction)

    // // Listen for viewer count updates
    // window.Echo.channel(`livestream.${props.stream.id}`)
    //   .listen('.viewer.count', (e) => {
    //     viewerCount.value = e.count
    //     addDebugLog(`Viewer count updated: ${e.count}`)
    //   })

    // useEcho(
    //     `livestream.${props.stream.id}`,
    //     'LiveStreamDataSent',
    //     (e) => {
    //         console.log('echo: ', e);   
    //     }
    // );
    
    connectionStatus.value = 'Connected'
    addDebugLog('All Reverb listeners set up successfully')
    
  } catch (error) {
    addDebugLog(`Error setting up Reverb listeners: ${error.message}`)
    connectionStatus.value = 'Error'
  }
}

onMounted(() => {
  addDebugLog('Component mounted, initializing.')
  
  initializePlayer()
  setupReverbListeners()
  
  // Join as viewer
//   axios.post(`/streams/${props.stream.id}`)
//     .then(response => {
//       viewerCount.value = response.data.viewer_count
//       addDebugLog(`Joined stream, viewer count: ${response.data.viewer_count}`)
//     })
//     .catch(error => {
//       addDebugLog(`Error joining stream: ${error.message}`)
//     })
})

onUnmounted(() => {
  addDebugLog('Component unmounting.')
  
  // Leave as viewer
//   axios.post(`/streams/${props.stream.id}/leave`)
  
  if (echo) {
    window.Echo.leaveChannel(`livestream.${props.stream.id}`)
    // window.Echo.leaveChannel(`livestream.${props.stream.id}.reactions`)
  }
  
  connectionStatus.value = 'Disconnected'
})
</script>