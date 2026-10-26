const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
    constructor() {
        this.listeners = {}; this.attributes = {}; this.dataset = {}; this.hover = false;
        const classes = new Set();
        this.classList = { contains: key => classes.has(key), add: key => classes.add(key), remove: key => classes.delete(key), toggle: (key, on) => on ? classes.add(key) : classes.delete(key) };
        this.style = { setProperty() {} };
        this.children = [];
    }
    addEventListener(type, fn) { (this.listeners[type] ??= []).push(fn); }
    removeEventListener(type, fn) { this.listeners[type] = (this.listeners[type] ?? []).filter(item => item !== fn); }
    emit(type, event = {}) { for (const fn of this.listeners[type] ?? []) fn(event); }
    setAttribute(key, value) { this.attributes[key] = value; }
    getAttribute(key) { return this.attributes[key]; }
    matches() { return this.hover; }
    contains(element) { return element === this || this.children?.includes(element); }
    getBoundingClientRect() { return { top: 100, right: 65, height: 200 }; }
    querySelector() { return this.link; }
    closest() { return this.category; }
    querySelectorAll() { return this.children; }
    focus() { document.activeElement = this; }
}
const document = new Element(); document.activeElement = null; document.body = new Element();
const drawer = new Element(); drawer.classList.add('compact');
const window = new Element(); window.innerWidth = 1280; window.innerHeight = 800; window.location = { href: 'http://localhost:8000/?page=dashboard' };
window.matchMedia = () => Object.assign(new Element(), { matches: false });
const buttons = [new Element(), new Element()];
const menus = buttons.map((button, index) => {
    const menu = new Element(), category = new Element();
    menu.link = new Element(); category.children = [button, menu, menu.link];
    button.textContent = 'Menu ' + index; button.classList.add('cat-toggle'); button.parentElement = category; button.category = category; button.nextElementSibling = menu;
    menu.previousElementSibling = button; menu.link.href = 'http://localhost:8000/?page=test';
    return menu;
});
const expand = new Element();
drawer.querySelector = selector => selector === '[data-drawer-expand]' ? expand : new Element();
drawer.querySelectorAll = selector => selector === '.drawer-submenu' ? menus : selector === 'a' ? menus.map(menu => menu.link) : buttons;
const ids = { 'sidebar-drawer': drawer, hamburger: new Element(), 'drawer-backdrop': new Element() };
document.getElementById = id => ids[id];
const tasks = new Map(); let sequence = 0, mobile = false;
const context = { document, window, URL, drawer, drawerSubmenus: menus, expandControl: new Element(),
    isMobile: () => mobile,
    setTimeout: (fn, delay) => { const id = ++sequence; tasks.set(id, { fn, delay }); return id; },
    clearTimeout: id => tasks.delete(id) };
const flush = () => { const pending = [...tasks.values()]; tasks.clear(); pending.forEach(task => task.fn()); };
window.requestAnimationFrame = fn => context.setTimeout(fn, 0);
window.cancelAnimationFrame = id => context.clearTimeout(id);
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/sidebar-menu.js'), 'utf8');
vm.runInNewContext(source, context);
assert.equal(drawer.classList.contains('compact'), true, 'Standalone script initializes before DOMContentLoaded');

assert.equal(menus.some(menu => menu.classList.contains('open') || menu.classList.contains('closing')), false, 'Initial menu is fully closed');
buttons[0].emit('mouseenter');
assert.equal(drawer.classList.contains('hover-expanded'), true, 'Hover expands sidebar labels before clicking');
assert.equal(menus[0].classList.contains('open'), false, 'Hover only highlights; it never opens a submenu');
buttons[0].emit('click');
assert.equal(menus[0].classList.contains('open'), true, 'Click opens the selected category');
assert.equal(buttons[0].getAttribute('aria-expanded'), 'true');
assert.equal(buttons[0].classList.contains('is-selected'), true);
buttons[1].emit('mouseenter');
assert.equal(menus[0].classList.contains('open'), true, 'Hovering another category leaves the open submenu unchanged');
assert.equal(menus[1].classList.contains('open'), false);
buttons[0].emit('click');
assert.equal(menus[0].classList.contains('open'), false, 'Second click closes the category');
assert.equal(buttons[0].getAttribute('aria-expanded'), 'false');
buttons[0].emit('click');
buttons[1].emit('click');
assert.equal(menus[0].classList.contains('open'), false);
assert.equal(menus[1].classList.contains('open'), true, 'Only the clicked category stays open');
assert.equal(buttons[1].classList.contains('is-selected'), true);
assert.equal(buttons[0].classList.contains('is-selected'), false);
buttons[1].category.emit('mouseleave'); flush();
assert.equal(menus[1].classList.contains('open'), true, 'Leaving keeps the submenu open');
buttons[1].category.emit('focusout'); flush();
assert.equal(menus[1].classList.contains('open'), true, 'Losing focus keeps the submenu open');
document.emit('click', { target: { closest: selector => selector === '#sidebar-drawer' ? drawer : null } });
assert.equal(menus[1].classList.contains('open'), true, 'Click inside the drawer keeps it open');
document.emit('click', { target: { closest: () => null } });
assert.equal(drawer.classList.contains('hover-expanded'), false, 'Outside click collapses the label rail');
assert.equal(menus[1].classList.contains('open'), false, 'Click outside closes the submenu');
assert.equal(buttons[1].getAttribute('aria-expanded'), 'false');
buttons[0].emit('keydown', { key: 'ArrowRight', preventDefault() {} });
assert.equal(document.activeElement, menus[0].link);
drawer.emit('keydown', { key: 'Escape' });
assert.equal(document.activeElement, buttons[0]);
assert.equal(menus[0].classList.contains('open'), false);
mobile = true; window.innerWidth = 600; buttons[0].emit('mouseenter'); flush();
assert.equal(menus[0].classList.contains('open'), false, 'Mobile ignores hover');
buttons[0].emit('click');
assert.equal(menus[0].classList.contains('open'), true, 'Mobile opens by tap');
buttons[0].emit('click');
assert.equal(menus[0].classList.contains('open'), false, 'Repeated mobile tap closes the submenu');
buttons[1].emit('click');
assert.equal(menus[0].classList.contains('open'), false, 'Mobile closes the previous category');
assert.equal(menus[1].classList.contains('open'), true);
assert.equal(buttons[1].classList.contains('is-selected'), true, 'Mobile selection follows the tapped category');
ids.hamburger.emit('click');
assert.equal(drawer.classList.contains('open'), true, 'Hamburger opens the main mobile menu');
assert.equal(menus.filter(menu => menu.classList.contains('open')).length, 0, 'Mobile navigation starts with icons only');
ids['drawer-backdrop'].emit('click');
assert.equal(drawer.classList.contains('open'), false, 'Tapping outside closes mobile navigation');
window.innerWidth = 820;
ids.hamburger.emit('click');
assert.equal(drawer.classList.contains('open'), true, 'Tablet opens the same main navigation');
assert.equal(menus.filter(menu => menu.classList.contains('open')).length, 0, 'Tablet also starts with icons only');
buttons[0].emit('click');
assert.equal(menus[0].classList.contains('open'), true, 'Tapping a tablet icon reveals its submenu');
menus.forEach((menu,index) => {
    menu.children = [menu.link,new Element(),new Element()];
    menu.scrollHeight = index ? 240 : 144;
    menu.getBoundingClientRect = () => ({height:menu.renderedHeight ?? (menu.classList.contains('open') ? menu.scrollHeight : 0)});
});
flush(); flush();
buttons[1].emit('click');
assert.equal(menus[1].style.height,'0px','Opening starts from the closed height');
assert.equal(menus[0].classList.contains('closing'),true,'Closing remains mounted for animation');
assert.equal(menus[0].inert,true,'Closing submenu cannot receive focus');
flush();
assert.equal(menus[1].style.height,'240px','Opening targets scrollHeight for this submenu');
assert.equal(menus[0].style.height,'0px','Closing targets zero on the next animation frame');
menus[0].renderedHeight = 72;
buttons[0].emit('click');
assert.equal(menus[0].style.height,'72px','Reversal starts from the current visible height');
flush();
assert.equal(menus[0].style.height,'144px');
menus[0].emit('transitionend',{target:menus[0],propertyName:'height'});
assert.equal(menus[0].style.height,'auto','Finished opening adapts to future content changes');
flush();
assert.equal(menus[1].classList.contains('closing'),false,'Closing clears its temporary state');
assert.equal(menus[1].style.height,'0px','Closed submenu has no leftover height');
window.matchMedia = () => Object.assign(new Element(), { matches:true });
buttons[0].emit('click');
assert.equal(menus[0].style.height,'0px','Reduced motion closes immediately');
assert.equal(menus[0].classList.contains('closing'),false);
window.matchMedia = () => Object.assign(new Element(), { matches:false });
menus[0].link.emit('click', { preventDefault() { throw new Error('Menu must preserve native link navigation'); } });
assert.equal(drawer.classList.contains('open'), false, 'Selecting a link closes mobile navigation');
ids.hamburger.emit('click');
window.emit('pageshow', { persisted: true });
assert.equal(drawer.classList.contains('open'), false, 'History restoration does not leave a blocking drawer');
assert.equal(document.body.classList.contains('mobile-menu-open'), false, 'History restoration releases page scrolling');
console.log('CompactMenuInteractionTest OK');
