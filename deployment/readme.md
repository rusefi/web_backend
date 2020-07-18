
Generated with the following command (nope, password is not 'password') - it's ${RUSEFI_PROXY_PASSWORD}

``keytool -genkey -keyalg RSA -alias selfsigned -keystore keystore.jks -storepass password -validity 360 -keysize 2048``


Converted using

keytool -importkeystore -srckeystore keystore.jks -destkeystore keystore.jks -deststoretype pkcs12