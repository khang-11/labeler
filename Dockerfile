# Labeler interface (and optionally a printer agent) in a container.
#   docker build -t labeler .
#   docker run --rm -p 8080:8080 -e LABELER_DEMO=1 labeler        # try it with a simulated printer
# See docker-compose.yml and docs/DOCKER.md.

# ptouch (Python) in a virtual environment, for the printer (USB or network).
FROM debian:bookworm-slim AS ptouch
ARG PTOUCH_REF=v1.2.1
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      ca-certificates git python3 python3-venv python3-pil python3-setuptools \
 && rm -rf /var/lib/apt/lists/*
RUN git clone https://github.com/khang-11/ptouch.git /src \
 && cd /src && git -c advice.detachedHead=false checkout "$PTOUCH_REF" \
 && python3 -m venv --system-site-packages /opt/ptouch \
 && /opt/ptouch/bin/pip install --no-cache-dir --no-deps . \
 && /opt/ptouch/bin/pip install --no-cache-dir packbits pyusb

FROM debian:bookworm-slim
ARG VERSION=dev
LABEL org.opencontainers.image.title="Labeler" \
      org.opencontainers.image.description="Web interface for Brother P-Touch label printers" \
      org.opencontainers.image.source="https://github.com/PhilippMundhenk/labeler" \
      org.opencontainers.image.licenses="MIT"
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      php-cli libgd3 libusb-1.0-0 ca-certificates python3 python3-pil \
 && rm -rf /var/lib/apt/lists/* \
 && groupadd --gid 1000 labeler && useradd --uid 1000 --gid 1000 --no-create-home --shell /usr/sbin/nologin labeler \
 && mkdir -p /data && chown 1000:1000 /data
COPY --from=ptouch /opt/ptouch /opt/ptouch
RUN ln -s /opt/ptouch/bin/ptouch /usr/local/bin/ptouch
COPY public /app/public
COPY src /app/src
COPY tools/ptouch-sim /app/tools/ptouch-sim
COPY config.example.php /app/
COPY docker/entrypoint.sh /usr/local/bin/labeler-entrypoint
RUN sed -i "s/VERSION = '[^']*'/VERSION = '${VERSION}'/" /app/src/Config.php \
 && chmod 755 /usr/local/bin/labeler-entrypoint /app/tools/ptouch-sim/ptouch
ENV LABELER_DATA_DIR=/data \
    LABELER_FONTS_DIR=/data/fonts \
    PORT=8080
VOLUME ["/data"]
EXPOSE 8080
USER 1000:1000
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s \
  CMD ["php", "-r", "exit(@file_get_contents('http://127.0.0.1:' . getenv('PORT') . '/api.php?action=health') === false ? 1 : 0);"]
ENTRYPOINT ["labeler-entrypoint"]
