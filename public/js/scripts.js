// redirect
function redirect(path) {
  setTimeout(() => {
    location.replace(path)
  }, 3000);
}

// refresh page
function refresh() {
  setTimeout(() => {
    location.reload();
  }, 5000);
}

// update button text
function updateButtonText(button_id, buttonText = "please wait...") {
  $(button_id).text(buttonText);
}

// disable button
function disableButton(button_id) {
  $(button_id).attr("disabled", true);
}


// datepickers
$("#datepicker").flatpickr();
$("#datepicker2").flatpickr();
$("#datepicker3").flatpickr();


// delete data with sweetalert
function deleteData(id, url) {
  Swal.fire({
    title: 'Are you sure?',
    text: "You won't be able to revert this!",
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#3085d6',
    cancelButtonColor: '#d33',
    confirmButtonText: 'Yes, delete it!',
    confirmButtonClass: 'btn btn-primary',
    cancelButtonClass: 'btn btn-danger ml-1',
    buttonsStyling: false,
  }).then(function (result) {
    if (result.value) {
      var data = {
        "_token": $('input[name=_token]').val(),
        "id": id
      }

      $.ajaxSetup({
        headers: {
          'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
      });

      $.ajax({
        url: url + id,
        method: "DELETE",
        data: data,
        dataType: 'JSON',
        success: function (response) {
          Swal.fire({
            icon: "success",
            title: 'Deleted!',
            text: response.status,
            confirmButtonClass: 'btn btn-success',
          }).then((result) => {
            location.reload();
          })
        }
      });

    }
    else if (result.dismiss === Swal.DismissReason.cancel) {
      Swal.fire({
        title: 'Cancelled',
        text: 'Good Choice 😍 Your data is safe :)',
        icon: 'error',
        confirmButtonClass: 'btn btn-success',
      })
    }
  })
}


// success toast alert
function successToastAlert(message, title = 'Success!!') {
  toastr.options = {
    "closeButton": true,
    "debug": false,
    "newestOnTop": true,
    "progressBar": true,
    "positionClass": "toastr-top-right",
    "preventDuplicates": false,
    "showDuration": "300",
    "hideDuration": "1000",
    "timeOut": "5000",
    "extendedTimeOut": "1000",
    "showEasing": "swing",
    "hideEasing": "linear",
    "showMethod": "fadeIn",
    "hideMethod": "fadeOut"
  };
  toastr.success(message, title);
}

// error toast alert
function errorToastAlert(message, title = 'Error!!') {
  toastr.options = {
    "closeButton": true,
    "debug": false,
    "newestOnTop": true,
    "progressBar": true,
    "positionClass": "toastr-top-right",
    "preventDuplicates": false,
    "showDuration": "300",
    "hideDuration": "1000",
    "timeOut": "5000",
    "extendedTimeOut": "1000",
    "showEasing": "swing",
    "hideEasing": "linear",
    "showMethod": "fadeIn",
    "hideMethod": "fadeOut"
  };
  toastr.error(message, title);
}

// warning toast alert
function warningToastAlert(message, title = 'Attention!!') {
  toastr.options = {
    "closeButton": true,
    "debug": false,
    "newestOnTop": true,
    "progressBar": true,
    "positionClass": "toastr-top-right",
    "preventDuplicates": false,
    "showDuration": "300",
    "hideDuration": "1000",
    "timeOut": "5000",
    "extendedTimeOut": "1000",
    "showEasing": "swing",
    "hideEasing": "linear",
    "showMethod": "fadeIn",
    "hideMethod": "fadeOut"
  };
  toastr.warning(message, title);
}

// info toast alert
function infoToastAlert(message, title = 'Information!!') {
  toastr.options = {
    "closeButton": true,
    "debug": false,
    "newestOnTop": true,
    "progressBar": true,
    "positionClass": "toastr-top-right",
    "preventDuplicates": false,
    "showDuration": "300",
    "hideDuration": "1000",
    "timeOut": "5000",
    "extendedTimeOut": "1000",
    "showEasing": "swing",
    "hideEasing": "linear",
    "showMethod": "fadeIn",
    "hideMethod": "fadeOut"
  };
  toastr.info(message, title);
}

function showModal(modal_id) {
  $(modal_id).modal('show');
}

// toggle drawer elements
function toggleDrawer(drawerId) {
  let drawerElement = document.getElementById(drawerId);

  let drawer = KTDrawer.getInstance(drawerElement);

  drawer.toggle();
}


// preview image before upload
function previewImage(input, selector_id) {
  if (input.files && input.files[0]) {
    var reader = new FileReader();

    reader.onload = function (e) {
      $(selector_id).attr('src', e.target.result);
    }

    reader.readAsDataURL(input.files[0]);
  }
}


function handleValidationErrors(error) {
  // Remove existing error messages and is-invalid class
  $(".is-invalid").removeClass("is-invalid");
  $(".invalid-feedback").remove();

  if (error.status == 422) {
    $.each(error.responseJSON.errors, function (key, val) {
      $(document)
        .find("[name=" + key + "]")
        .addClass("is-invalid")
        .after('<div class="invalid-feedback">' + val + "</div>");
    });
  } else {
    // Catch-all for other errors (500, 403, etc.)
    let message = error.responseJSON?.message || "An unexpected error occurred. Please try again.";
    errorToastAlert(message);
  }
}

function handleSuccessResponse(response) {
  if (response.status == "success") {
    successToastAlert(response.message);
    refresh();
  }
}

// process file upload with filepond
function processFileUploadWithFilePond(
  input,
  maxFileSize = "2MB",
  acceptedFileTypes = [],
  processPath,
  revertPath
) {
  FilePond.registerPlugin(FilePondPluginFileValidateType);

  const inputElement = document.getElementById(input);

  // Create a FilePond instance
  const pond = FilePond.create(inputElement, {
    maxFileSize: maxFileSize,
    acceptedFileTypes: acceptedFileTypes,
  });

  FilePond.setOptions({
    server: {
      process: processPath,
      revert: revertPath,
      headers: {
        "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
      },
    },
  });
}

function ajaxStoreRequestHandler(formId, buttonId) {
  $(formId).off("submit").submit(function (e) {
    e.preventDefault();

    let submit_button = $(buttonId);
    let formdata = new FormData(this);

    // disable button and turn on loading spinner
    submit_button.attr("disabled", true);
    submit_button.attr("data-kt-indicator", "on");

    $.ajax({
      type: "POST",
      url: $(this).attr("action"),
      data: formdata,
      cache: false,
      processData: false,
      contentType: false,
      success: function (response) {
        handleSuccessResponse(response, submit_button);
      },
      error: function (error) {
        // enable button and turn off loading spinner
        submit_button.attr("disabled", false);
        submit_button.attr("data-kt-indicator", "off");
        handleValidationErrors(error);
      },
    });
  });
}

function ajaxUpdateRequestHandler(formId, formAction, buttonId) {
  $(formId).off("submit").submit(function (e) {
    e.preventDefault();

    let submit_button = $(buttonId);
    let formdata = new FormData(this);
    formdata.append("_method", "PUT");

    // disable button and turn on loading spinner
    submit_button.attr("disabled", true);
    submit_button.attr("data-kt-indicator", "on");

    $.ajax({
      type: "post",
      url: formAction,
      data: formdata,
      cache: false,
      processData: false,
      contentType: false,
      success: function (response) {
        handleSuccessResponse(response, buttonId);
      },
      error: function (errors) {
        // enable button and turn off loading spinner
        submit_button.attr("disabled", false);
        submit_button.attr("data-kt-indicator", "off");

        handleValidationErrors(errors);
      },
    });
  });
}

//   create new user
function createUser() {
  showModal('#createUserModal')

  ajaxStoreRequestHandler("#createUserForm", "#createUserButton");
}


// update user
function updateUser(user_id) {
  showModal('#updateUserModal')

  let baseUrl = window.location.pathname.startsWith('/user-management') ? "/user-management/users/" : "/users/";

  $.get(baseUrl + user_id + "/edit", function (result) {
    console.log(result);
    $.each(result.data, function (key, value) {
      $('#e' + key).val(value)
    })

    $('#estatus').val(result.data.status).change();
    $('#eroles').val(result.roles).change();
  });

  ajaxUpdateRequestHandler(
    "#updateUserForm",
    baseUrl + user_id,
    "#updateUserButton"
  );
}

// Resend account password
function resendAccountPassword(userId) {
  showModal("#resendAccountPasswordModal");

  ajaxUpdateRequestHandler(
    "#resendAccountPasswordForm",
    "/user-management/users/" + userId + "/resend-password",
    "#resendAccountPasswordButton"
  );
}
