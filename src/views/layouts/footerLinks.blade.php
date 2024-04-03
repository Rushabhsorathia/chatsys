<script src="https://js.pusher.com/7.2.0/pusher.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@joeattardi/emoji-button@3.0.3/dist/index.min.js"></script>
<script >
    // Gloabl Chatsys variables from PHP to JS
    window.Chatsys = {
        name: "{{ config('Chatsys.name') }}",
        sounds: {!! json_encode(config('Chatsys.sounds')) !!},
        allowedImages: {!! json_encode(config('Chatsys.attachments.allowed_images')) !!},
        allowedFiles: {!! json_encode(config('Chatsys.attachments.allowed_files')) !!},
        maxUploadSize: {{ Chatsys::getMaxUploadSize() }},
        pusher: {!! json_encode(config('Chatsys.pusher')) !!},
        pusherAuthEndpoint: '{{route("pusher.auth")}}'
    };
    window.Chatsys.allAllowedExtensions = Chatsys.allowedImages.concat(Chatsys.allowedFiles);
</script>
<script src="{{ asset('js/Chatsys/utils.js') }}"></script>
<script src="{{ asset('js/Chatsys/code.js') }}"></script>
