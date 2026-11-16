<template id="custom-hello">
    <slot name="hellotext"></slot>
</template>
<custom-hello id="ht"><h1 slot="hellotext">Hello, Default!</h1></custom-hello>

<script>
console.log("======== BEGIN =============="); 
class CustomHello extends ShadowComponent {
    static observables = ["hellotext"]; 
    constructor(){
        super();
        console.log("constructor fired"); 

        console.log(this); 
        console.log(this.children[0]);
        console.log("=======");
        //this["specialAttribute"] = "Really?? OKAY"; 
    }

    /*
    connectedCallback() {
        console.log("connectedCallback fired"); 
        this.attachShadow({ mode: 'open' });
        this.shadowRoot.innerHTML = `<p>${this.getAttribute('text')}</p>`;
    }*/
}
customElements.define('custom-hello', CustomHello);
</script>
