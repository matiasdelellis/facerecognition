#!/bin/bash

function make_virtual_env () {
    echo "Cleaning last virtual env.."
    rm -f -r opt/

    echo "Setting virtual env.."
    virtualenv-3 --system-site-packages opt/
    cd opt
    source bin/activate
    pip3 install face_recognition
    deactivate

    echo "Download nextcloud_face_recognition_cmd.."
    wget https://raw.githubusercontent.com/matiasdelellis/nextcloud_face_recognition_cmd/master/nextcloud_face_recognition_cmd/nextcloud_face_recognition_cmd.py -P bin/

    echo "Write nextcloud-face-recognition-cmd wrapper to launch virtual env."
    echo '#!/bin/bash' > bin/nextcloud-face-recognition-cmd
    echo 'source '$PWD'/bin/activate' >> bin/nextcloud-face-recognition-cmd
    echo 'python3 '$PWD'/bin/nextcloud_face_recognition_cmd.py $@' >> bin/nextcloud-face-recognition-cmd
    echo 'deactivate' >> bin/nextcloud-face-recognition-cmd
    chmod +x bin/nextcloud-face-recognition-cmd

    echo
    echo "Make virtual env done.."
    echo
    echo "If all went well you have all the dependencies to work."
    echo "Remember install the tool with 'install' argument.."
}

function install_tool () {
    rm -f /usr/bin/nextcloud-face-recognition-cmd
    ln -s $PWD/opt/bin/nextcloud-face-recognition-cmd /usr/bin/nextcloud-face-recognition-cmd

    echo
    echo "Everything finished. You could use the application in nextcloud!"
}

if [ "$1" == "virtualenv" ]
then
    make_virtual_env
elif [ "$1" == "install" ]
then
    if [ "$EUID" -ne 0 ]
        then echo "Please run as root"
        exit
    fi
    install_tool
else
    echo "D'Oh!. Specify 'virtualenv' or 'install'.."
fi
