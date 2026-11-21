<x-modal id="resendAccountPasswordModal" title="Send account recovery link">
    <div class="text-center fs-5">
        Send this person a secure account recovery link? Their password stays unchanged until they choose a new one.
    </div>
    <div class="d-flex justify-content-center pt-10 mb-3">
        <button type="reset" class="btn btn-light me-3" data-bs-dismiss="modal" aria-label="Close">Cancel</button>
        <form action="#" method="post" id="resendAccountPasswordForm">
            @csrf
            <button type="submit" class="btn btn-primary" id="resendAccountPasswordButton">Send recovery link</button>
        </form>
    </div>
</x-modal>
