<?php

class TestSuite
{
    /**@Param
     * Reflection Object:
     (
     [methods] => Array
     (
     [0] => export
     [1] => __construct
     [2] => __toString
     [3] => getName
     [4] => isInternal
     [5] => isUserDefined
     [6] => isInstantiable
     [7] => isCloneable
     [8] => getFileName
     [9] => getStartLine
     [10] => getEndLine
     [11] => getDocComment
     [12] => getConstructor
     [13] => hasMethod
     [14] => getMethod
     [15] => getMethods
     [16] => hasProperty
     [17] => getProperty
     [18] => getProperties
     [19] => hasConstant
     [20] => getConstants
     [21] => getConstant
     [22] => getInterfaces
     [23] => getInterfaceNames
     [24] => isInterface
     [25] => getTraits
     [26] => getTraitNames
     [27] => getTraitAliases
     [28] => isTrait
     [29] => isAbstract
     [30] => isFinal
     [31] => getModifiers
     [32] => isInstance
     [33] => newInstance
     [34] => newInstanceWithoutConstructor
     [35] => newInstanceArgs
     [36] => getParentClass
     [37] => isSubclassOf
     [38] => getStaticProperties
     [39] => getStaticPropertyValue
     [40] => setStaticPropertyValue
     [41] => getDefaultProperties
     [42] => isIterateable
     [43] => implementsInterface
     [44] => getExtension
     [45] => getExtensionName
     [46] => inNamespace
     [47] => getNamespaceName
     [48] => getShortName
     )

     [properties] => Array
     (
     [name] => Auth
     )

     )
     */

    function reflection()

    {
        class_exists("Auth", true);
        $reflector = new ReflectionClass('Auth');
        // print_x($reflector); die();
        //print_x($reflector);
        // print_x($reflector -> getMethods());
        // to get the Class DocBlock
        // echo $reflector -> getDocComment();
        // print_x($reflector);
        // print_x($reflector->getMethods());
        // print_x($reflector->getProperties());
        //

        foreach ($reflector->getMethods() as $method) {// * see documentation
            // below function.
            // echo $method -> invoke();
            //d/ie();
            echo '<h1>' . $method -> name . "</h1>";
            echo '<h2>' . $method -> getModifiers() . '</h2>';
            // echo '<h3>' . $method -> getPrototype() . '</h3>';
            echo '<h4>' . $method -> getExtensionName() . '</h4>';

            echo '<h4>' . $method -> getNumberOfParameters() . '</h4>';
            //Console::table("ReflectionMethods") ->
            // setHeaders(get_class_methods($method))->setFirstHeader("AuthMethods#");

            // print_x($method -> getDocComment());
        }
        // to get the Method DocBlock
    }/**

     * Array
     (
     [methods] => Array
     (
     [0] => export
     [1] => __construct
     [2] => __toString
     [3] => isPublic
     [4] => isPrivate
     [5] => isProtected
     [6] => isAbstract
     [7] => isFinal
     [8] => isStatic
     [9] => isConstructor
     [10] => isDestructor
     [11] => getClosure
     [12] => getModifiers
     [13] => invoke
     [14] => invokeArgs
     [15] => getDeclaringClass
     [16] => getPrototype
     [17] => setAccessible
     [18] => inNamespace
     [19] => isClosure
     [20] => isDeprecated
     [21] => isInternal
     [22] => isUserDefined
     [23] => isGenerator
     [24] => getClosureThis
     [25] => getClosureScopeClass
     [26] => getDocComment
     [27] => getEndLine
     [28] => getExtension
     [29] => getExtensionName
     [30] => getFileName
     [31] => getName
     [32] => getNamespaceName
     [33] => getNumberOfParameters
     [34] => getNumberOfRequiredParameters
     [35] => getParameters
     [36] => getShortName
     [37] => getStartLine
     [38] => getStaticVariables
     [39] => returnsReference
     )

     [properties] => Array
     (
     [name] => register
     [class] => Auth
     )

     )
     * */

}
