<?php ?>

<form enctype="multipart/form-data">
    <input type="text" name="text"> 
    <input type="file" name="file"> 
    <input type="file" name="file3"> 
    <input type="file" name="file2"> 
    <input type="submit">
</form>
<div id="responsed"></div>
<script>


document.querySelector("form").addEventListener("submit", function(e){
    console.log("submitted...");     
    
    e.stopPropagation();
    e.preventDefault(); 

    fetch("/test/qform", {
        method: "POST",
        body: new FormData(document.querySelector("form")),
        cache: "no-store"
    }).then(function(response){
        return response.text();
    }).then(function(response){
        console.log(response); 
        document.getElementById("responsed").innerHTML = response; 
    });
}); 

</script>
