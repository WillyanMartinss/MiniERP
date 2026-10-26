const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
function element(props = {}) {
  const listeners = {};
  const classes = new Set();
  return Object.assign({disabled:false,textContent:'Entrar',type:'password',
    classList:{add: x=>classes.add(x),remove:x=>classes.delete(x),contains:x=>classes.has(x)},
    addEventListener:(name,fn)=>(listeners[name]??=[]).push(fn),
    emit:(name,event={})=>(listeners[name]??[]).forEach(fn=>fn(event)),
    setAttribute(name,value){this[name]=value;}, focus(){this.focused=true;}
  },props);
}
const email=element(), password=element(), signupPassword=element();
const loginForm=element({querySelector: selector=>selector.includes('email')?email:password});
const signupForm=element();
const button=element(), signupButton=element({disabled:true}), toggle=element();
const ids={loginForm,signupForm,loginBtn:button,signupBtnForm:signupButton,togglePassword:toggle};
const document=element({getElementById:id=>ids[id]??null});
const window=element({matchMedia:()=>({matches:false})});
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../public/assets/login.js'),'utf8'),{document,window});
document.emit('DOMContentLoaded');
assert.equal(email.focused,undefined,'Mobile must not open the keyboard automatically');
toggle.emit('click');
assert.equal(password.type,'text','Show password targets the actual login form');
assert.equal(signupPassword.type,'password');
toggle.emit('click');
assert.equal(password.type,'password');
loginForm.emit('submit');
assert.equal(button.disabled,true,'Tenant login also gets submit feedback');
assert.equal(button.textContent,'Entrando...');
window.emit('pageshow');
assert.equal(button.disabled,false,'Returning from browser history permits another attempt');
assert.equal(button.textContent,'Entrar');
assert.equal(signupButton.disabled,true,'Unavailable registration remains disabled');
console.log('LoginFormInteractionTest OK');
