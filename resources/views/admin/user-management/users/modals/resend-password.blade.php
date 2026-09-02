<x-modal id="resendAccountPasswordModal" title="Resend Password">
    <div class="text-center fs-5">
        Are you certain you want to change this user's account password?
        Please be advised that this decision cannot be reversed.
    </div>
    <div class="d-flex justify-content-center pt-10 mb-3">
        <button type="reset" class="btn btn-light me-3" data-bs-dismiss="modal" aria-label="Close">Cancel</button>
        <form action="#" method="post" id="resendAccountPasswordForm">
            @csrf
            <button type="submit" class="btn btn-primary" id="resendAccountPasswordButton">Yes Resend Password!</button>
        </form>
    </div>
</x-modal>
