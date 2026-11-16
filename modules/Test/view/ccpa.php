<div id="ccpa_outer_container">
<h1>CCPA Penalty Calculator</h1>
<p class="centered">Use this interactive calculator to determine possible violation fines from the State of California and/or impacted California Consumers</p>
<p class="disclaimer smaller centered italic">This calculator is for educational and hypothetical purposes only and not meant to provide legal guidance</p>
<div id="ccpa_inner_container">
    <h2>State of CA Penalties</h2>
    <p>$2,500 for unintentional or $7,500 for intentional violations. Only the CA Attorney General can pursue these penalties</p>
    <label><input id="violation_count" type="number"/> Number of violations (e.g. CA consumers impacted)</label>
    <p class="violation_type_text">Choose One: 
        <fieldset id="intention">
            <label><input checked="checked" type="radio" name="intention" value="2500"/> Unintentional violation</label>
            <label><input type="radio" name="intention" value="7500"/> Intentional violation</label>
        </fieldset>
    </p>
    <label>$&nbsp;<input type="text" id="ca_penalty_amnt"/>Total Estimated State of CA fine amount</label>
    <hr/>
    <h2>CA Consumer Damages</h2>
    <p>$100-$750 per incident, per consumer — or actual damages, if higher — for damage caused by a data breach. </p>
    <label><input id="plaintiff_count" type="number" /> Number of suing consumers (if different from number of violations)</label>
    <p class="average_amount_per_consumer">Average amount per consumer:</p>
    <label> $100 <input id="comppercon" type="range" min="100" max="750"/> $750</label>
    <label>$&nbsp;<input type="text" id="consumer_damage"/>Total estimated consumer damages</label>
    <label>$&nbsp;<input type="text" id="grand_fine_amnt"/>Grand Total Estimated State of CA violations and consumer damages</label>
</div>
<script>

function formatMoney(number, decPlaces, decSep, thouSep) {
    return number.toString().replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,");
    decPlaces = isNaN(decPlaces = Math.abs(decPlaces)) ? 2 : decPlaces,
    decSep = typeof decSep === "undefined" ? "." : decSep;
    thouSep = typeof thouSep === "undefined" ? "," : thouSep;
    var sign = number < 0 ? "-" : "";
    var i = String(parseInt(number = Math.abs(Number(number) || 0).toFixed(decPlaces)));
    var j = (j = i.length) > 3 ? j % 3 : 0;

    return sign +
        (j ? i.substr(0, j) + thouSep : "") +
        i.substr(j).replace(/(\decSep{3})(?=\decSep)/g, "$1" + thouSep) +
        (decPlaces ? decSep + Math.abs(number - i).toFixed(decPlaces).slice(2) : "");
}

var violation_count = document.querySelector("#violation_count");
var ca_penalty_type = "2500"; 
var ca_penalty_amnt = document.querySelector("#ca_penalty_amnt");


var plaintiff_count = document.querySelector("#plaintiff_count"); 
var consumer_damage = document.querySelector("#consumer_damage"); 

var grand_fine_amnt = document.querySelector("#grand_fine_amnt"); 



function calculatePenalty(){

    return ca_penalty_amnt.value = formatMoney((violation_count.value * ca_penalty_type));  

}

function calculateConsumerDmg(){
    var avg_amount = document.querySelector("#comppercon").value;
    return consumer_damage.value = formatMoney((plaintiff_count.value * avg_amount))    
}

function calculateGrandTotal(){
    
    a = parseInt(ca_penalty_amnt.value.replace(/,/g,""));
    console.log(a);
    b = parseInt(consumer_damage.value.replace(/,/g,""));
    console.log(b);
    return grand_fine_amnt.value = formatMoney(a + b); 
}


document.querySelectorAll("#ccpa_outer_container input").forEach(function(e){

    e.addEventListener("change",function(e){
        if(this.type == "radio"){
            ca_penalty_type = this.value;    
        }

        calculatePenalty();
        calculateConsumerDmg();
        calculateGrandTotal(); // can optimize via not doing all three indiscriminately.
    });

});

//document.querySelectorAll("#
</script>
