FROM nextcloud

RUN apt-get update
RUN apt-get install -y \
  git libopenblas-dev \
  liblapack-dev libx11-dev \
  pkg-config cmake libbz2-dev


# install dlib
WORKDIR /
RUN git clone https://github.com/davisking/dlib.git
RUN mkdir /dlib/dlib/build
WORKDIR /dlib/dlib/build
RUN cmake -DBUILD_SHARED_LIBS=ON ..
RUN make -j$(nproc)
RUN make install
RUN rm -rf /dlib

# install pdlib
WORKDIR /usr/src/php/ext/
RUN git clone https://github.com/goodspb/pdlib.git
WORKDIR /usr/src/php/ext/pdlib
RUN phpize
RUN ./configure --enable-debug
RUN make -j$(nproc)

# Installing extensions
RUN docker-php-ext-install bz2
RUN docker-php-ext-install pdlib

# Memory limit should be raised
RUN echo "memory_limit=2048M" >> /usr/local/etc/php/conf.d/memory-limit.ini

WORKDIR /var/www/html
