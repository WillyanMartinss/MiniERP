document.addEventListener('DOMContentLoaded', function(){
  var form = document.getElementById('loginForm');
  var email = form?.querySelector('input[name="email"]');
  if (email && window.matchMedia('(min-width: 900px) and (pointer: fine)').matches) {
    email.focus();
  }

  function bindSubmitFeedback(form, button, loadingText) {
    if (!form || !button) return;
    var initialDisabled = button.disabled;
    var initialText = button.textContent;
    form.addEventListener('submit', function(event) {
      if (event.defaultPrevented) return;
      button.disabled = true;
      button.classList.add('loading');
      button.textContent = loadingText;
    });
    window.addEventListener('pageshow', function() {
      button.disabled = initialDisabled;
      button.classList.remove('loading');
      button.textContent = initialText;
    });
  }
  bindSubmitFeedback(form, document.getElementById('loginBtn'), 'Entrando...');

  // toggle de visibilidade da senha
  var toggle = document.getElementById('togglePassword');
  if(toggle){
    toggle.addEventListener('click', function(){
      var pwd = form?.querySelector('input[name="senha"]');
      if(!pwd) return;
      if(pwd.type === 'password'){
        pwd.type = 'text';
        toggle.setAttribute('aria-label', 'Ocultar senha');
      } else {
        pwd.type = 'password';
        toggle.setAttribute('aria-label', 'Mostrar senha');
      }
    });
  }

  // slider sign in / sign up
  var signUpButton = document.getElementById('signUp');
  var signInButton = document.getElementById('signIn');
  var container = document.getElementById('container');
  if(signUpButton && signInButton && container){
    signUpButton.addEventListener('click', function(){ container.classList.add('right-panel-active'); });
    signInButton.addEventListener('click', function(){ container.classList.remove('right-panel-active'); });
  }

  bindSubmitFeedback(document.getElementById('signupForm'), document.getElementById('signupBtnForm'), 'Registrando...');
});
