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
            <div class="text-2xl font-bold">{{ chunksSent }}</div>
            <div class="text-sm text-gray-600">Chunks Sent</div>
          </div>
        </div>

        <!-- Debug Info -->
        <div class="mt-4 p-4 bg-gray-100 rounded-lg">
          <h3 class="font-semibold mb-2">Broadcast Debug</h3>
          <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
              <div>Recording State: <span class="font-mono">{{ recordingState }}</span></div>
              <div>Media Stream: <span class="font-mono">{{ mediaStreamState }}</span></div>
              <div>Last Chunk: <span class="font-mono">{{ lastChunkTime }}</span></div>
            </div>
            <div>
              <div>Chunk Size: <span class="font-mono">{{ lastChunkSize }}KB</span></div>
              <div>Send Errors: <span class="font-mono">{{ sendErrors }}</span></div>
              <div>Bitrate: <span class="font-mono">{{ currentBitrate }}kbps</span></div>
            </div>
          </div>
          
          <div class="mt-4 flex gap-2">
            <button 
              @click="testConnection"
              class="bg-blue-500 text-white px-3 py-1 rounded-sm text-sm"
            >
              Test Connection
            </button>
            <button 
              @click="toggleDebugMode"
              class="bg-purple-500 text-white px-3 py-1 rounded-sm text-sm"
            >
              {{ debugMode ? 'Hide' : 'Show' }} Debug Logs
            </button>
          </div>
          
          <div v-if="debugMode" class="mt-4 text-sm">
            <div class="bg-white p-2 rounded-sm border max-h-32 overflow-y-auto">
              <div v-for="(log, index) in debugLogs" :key="index" class="text-xs mb-1">
                <span class="text-gray-500">{{ log.time }}</span>: {{ log.message }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Live Chat -->
      <div class="lg:col-span-1">
        <!-- <LiveChat :stream-id="stream.id" @message-sent="chatCount++" /> -->
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
// import LiveChat from '@/Components/LiveChat.vue'

const props = defineProps({
  stream: Object,
})

const previewVideo = ref(null)
const isStreaming = ref(false)
const viewerCount = ref(0)
const chatCount = ref(0)
const chunksSent = ref(0)

// Debug states
const recordingState = ref('Not started')
const mediaStreamState = ref('Not initialized')
const lastChunkTime = ref('Never')
const lastChunkSize = ref(0)
const sendErrors = ref(0)
const currentBitrate = ref(2500)
const debugMode = ref(true)
const debugLogs = ref([])

let mediaRecorder = null
let stream = null
let echo = null

const addDebugLog = (message) => {
  debugLogs.value.unshift({
    id: Date.now(),
    time: new Date().toLocaleTimeString(),
    message: message
  })
  
  if (debugLogs.value.length > 50) {
    debugLogs.value = debugLogs.value.slice(0, 50)
  }
  
}

const testConnection = async () => {
  addDebugLog('Testing connection...')
  
  try {
    const response = await axios.get(`/streams/${props.stream.id}/stats`)
    addDebugLog(`Connection test successful: ${JSON.stringify(response.data)}`)
  } catch (error) {
    addDebugLog(`Connection test failed: ${error.message}`)
  }
}

const processChunkAlternative = async (blob) => {
  try {
    // Method 1: Using ArrayBuffer
    const arrayBuffer = await blob.arrayBuffer()
    const uint8Array = new Uint8Array(arrayBuffer)
    
    // Convert to base64 manually
    let binary = ''
    for (let i = 0; i < uint8Array.length; i++) {
      binary += String.fromCharCode(uint8Array[i])
    }
    const base64Data = btoa(binary)
    
    addDebugLog(`ArrayBuffer method: ${arrayBuffer.byteLength} bytes -> ${base64Data.length} base64 chars`)
    addDebugLog(`Base64 preview: ${base64Data.substring(0, 50)}`)
    
    return base64Data
  } catch (error) {
    addDebugLog(`ArrayBuffer method failed: ${error.message}`)
    throw error
  }
}

const startBroadcast = async () => {
  addDebugLog('Starting broadcast...')
  setupReverbListeners();

  try {
    // Get user media with high quality audio
    addDebugLog('Requesting user media...')
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

    addDebugLog('User media obtained successfully')
    mediaStreamState.value = 'Active'
    previewVideo.value.srcObject = stream

    // Check MediaRecorder support
    const mimeTypes = [
      'video/webm;codecs=vp8,opus',
      'video/webm;codecs=vp9,opus', 
      'video/webm;codecs=vp8',
      'video/webm',
      'video/mp4'
    ]
    
    let selectedMimeType = null
    for (const mimeType of mimeTypes) {
      if (MediaRecorder.isTypeSupported(mimeType)) {
        selectedMimeType = mimeType
        addDebugLog(`Using MIME type: ${mimeType}`)
        break
      }
    }
    
    if (!selectedMimeType) {
      throw new Error('No supported MIME types found')
    }

    // Create MediaRecorder with optimized settings
    const options = {
      mimeType: selectedMimeType,
    }
    
    // Add bitrate if supported
    if (selectedMimeType.includes('webm')) {
      options.videoBitsPerSecond = currentBitrate.value * 1000
      options.audioBitsPerSecond = 320000 // 320kbps audio
    }
    
    mediaRecorder = new MediaRecorder(stream, options)
    addDebugLog(`MediaRecorder created with options: ${JSON.stringify(options)}`)

    // Handle recording states
    mediaRecorder.onstart = () => {
      addDebugLog('MediaRecorder started')
      recordingState.value = 'Recording'
    }
    
    mediaRecorder.onstop = () => {
      addDebugLog('MediaRecorder stopped')
      recordingState.value = 'Stopped'
    }
    
    mediaRecorder.onerror = (event) => {
      addDebugLog(`MediaRecorder error: ${event.error}`)
      recordingState.value = 'Error'
    }

    // Handle data chunks
    mediaRecorder.ondataavailable = async (event) => {
      if (event.data.size > 0) {
        lastChunkTime.value = new Date().toLocaleTimeString()
        lastChunkSize.value = Math.round(event.data.size / 1024)
        
        addDebugLog(`Chunk available: ${lastChunkSize.value}KB, type: ${event.data.type}`)
        
        try {
  const base64Data = await processChunkAlternative(event.data)
  
  // Send to server
  const response = await axios.post(`/streams/${props.stream.id}/data`, {
    chunk: base64Data,
    type: 'video',
    mime_type: event.data.type,
    chunk_size: event.data.size,
    method: 'arraybuffer'
  });

  
  
  chunksSent.value++
  addDebugLog(`Chunk #${chunksSent.value} sent via ArrayBuffer method`)
  
} catch (error) {
  sendErrors.value++
  addDebugLog(`ArrayBuffer method error: ${error.message}`)
}

        // const reader = new FileReader()
        // reader.onload = async () => {
        //   try {
        //     // Debug the reader result
        //     addDebugLog(`FileReader result type: ${typeof reader.result}`)
        //     addDebugLog(`FileReader result length: ${reader.result?.length}`)
        //     addDebugLog(`FileReader result preview: ${reader.result?.substring(0, 100)}...`)
            
        //     // Make sure we have a valid data URL
        //     if (!reader.result || typeof reader.result !== 'string') {
        //       throw new Error('Invalid FileReader result')
        //     }
            
        //     // Check if it contains the data URL prefix
        //     if (!reader.result.includes('data:')) {
        //       throw new Error('Not a valid data URL')
        //     }
            
        //     const base64Data = reader.result.split(',')[1]
            
        //     if (!base64Data || base64Data.length === 0) {
        //       throw new Error('No base64 data found after comma')
        //     }
            
        //     addDebugLog(`Base64 data length: ${base64Data.length}`)
        //     addDebugLog(`Base64 preview: ${base64Data.substring(0, 50)}...`)
            
        //     const response = await axios.post(`/streams/${props.stream.id}/data`, {
        //       chunk: base64Data,
        //       type: 'video',
        //       mime_type: event.data.type,
        //       chunk_size: event.data.size
        //     })
            
        //     chunksSent.value++
        //     addDebugLog(`Chunk #${chunksSent.value} sent successfully, response: ${JSON.stringify(response.data)}`)
            
        //   } catch (error) {
        //     sendErrors.value++
        //     addDebugLog(`Error sending chunk #${chunksSent.value + 1}: ${error.message}`)
            
        //     if (error.response) {
        //       addDebugLog(`Response status: ${error.response.status}`)
        //       addDebugLog(`Response data: ${JSON.stringify(error.response.data)}`)
        //     }
        //   }
        // }
        
    //     reader.onerror = (error) => {
    //       addDebugLog(`FileReader error: ${error}`)
    //     }
        
    //     reader.readAsDataURL(event.data)
    //   } else {
    //     addDebugLog('Received empty chunk')
      }
    }

    // Start recording in small chunks for low latency
    const chunkInterval = 500 // 500ms chunks
    mediaRecorder.start(chunkInterval)
    isStreaming.value = true
    addDebugLog(`Recording started with ${chunkInterval}ms chunk interval`)

    // Notify server
    await axios.post(`/streams/${props.stream.id}/start`)
    addDebugLog('Stream start notification sent to server')

    // setupReverbListeners()

  } catch (error) {
    addDebugLog(`Error starting broadcast: ${error.message}`)
    recordingState.value = 'Failed'
    alert(`Could not start stream: ${error.message}`)
  }
}

const stopBroadcast = async () => {
  addDebugLog('Stopping broadcast...')
  
  try {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
      mediaRecorder.stop()
    }
    
    if (stream) {
      stream.getTracks().forEach(track => {
        track.stop()
        addDebugLog(`Stopped ${track.kind} track`)
      })
    }
    
    isStreaming.value = false
    mediaStreamState.value = 'Stopped'
    recordingState.value = 'Stopped'

    await axios.post(`/streams/${props.stream.id}/stop`)
    addDebugLog('Stream stop notification sent to server')
    
  } catch (error) {
    addDebugLog(`Error stopping broadcast: ${error.message}`)
  }
}

const setupReverbListeners = () => {
  addDebugLog('Setting up Reverb listeners...')
  
  try {
    window.Echo.channel(`livestream.${props.stream.id}`)
        .listen('.LiveStreamStarted' , (e) => {
            
        })
      .listen('.viewer.count', (e) => {
        viewerCount.value = e.count
        addDebugLog(`Viewer count updated: ${e.count}`)
      })
    
    addDebugLog('Reverb listeners set up successfully')
    
  } catch (error) {
    addDebugLog(`Error setting up Reverb listeners: ${error.message}`)
  }
}

const toggleDebugMode = () => {
  debugMode.value = !debugMode.value
}

onMounted(() => {
  addDebugLog('Broadcast component mounted')
  
  // Get initial viewer count
  axios.get(`/streams/${props.stream.id}/stats`)
    .then(response => {
      viewerCount.value = response.data.viewer_count
      addDebugLog(`Initial viewer count: ${response.data.viewer_count}`)
    })
    .catch(error => {
      addDebugLog(`Error getting initial stats: ${error.message}`)
    })
})

onUnmounted(() => {
  addDebugLog('Broadcast component unmounting')
  stopBroadcast()
  
//   if (echo) {
    window.Echo.leaveChannel(`livestream.${props.stream.id}`)
//   }
})
</script>