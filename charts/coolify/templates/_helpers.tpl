{{/*
Expand the name of the chart.
*/}}
{{- define "coolify.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Create a default fully qualified app name.
*/}}
{{- define "coolify.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}

{{/*
Create chart name and version as used by the chart label.
*/}}
{{- define "coolify.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Common labels
*/}}
{{- define "coolify.labels" -}}
helm.sh/chart: {{ include "coolify.chart" . }}
{{ include "coolify.selectorLabels" . }}
{{- if .Chart.AppVersion }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{- end }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{/*
Selector labels
*/}}
{{- define "coolify.selectorLabels" -}}
app.kubernetes.io/name: {{ include "coolify.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{/*
Soketi labels
*/}}
{{- define "coolify.soketi.labels" -}}
helm.sh/chart: {{ include "coolify.chart" . }}
{{ include "coolify.soketi.selectorLabels" . }}
{{- if .Chart.AppVersion }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{- end }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{/*
Soketi selector labels
*/}}
{{- define "coolify.soketi.selectorLabels" -}}
app.kubernetes.io/name: {{ include "coolify.name" . }}-soketi
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/component: soketi
{{- end }}

{{/*
PostgreSQL labels
*/}}
{{- define "coolify.postgresql.labels" -}}
helm.sh/chart: {{ include "coolify.chart" . }}
{{ include "coolify.postgresql.selectorLabels" . }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{/*
PostgreSQL selector labels
*/}}
{{- define "coolify.postgresql.selectorLabels" -}}
app.kubernetes.io/name: {{ include "coolify.name" . }}-postgresql
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/component: postgresql
{{- end }}

{{/*
Redis labels
*/}}
{{- define "coolify.redis.labels" -}}
helm.sh/chart: {{ include "coolify.chart" . }}
{{ include "coolify.redis.selectorLabels" . }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{/*
Redis selector labels
*/}}
{{- define "coolify.redis.selectorLabels" -}}
app.kubernetes.io/name: {{ include "coolify.name" . }}-redis
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/component: redis
{{- end }}

{{/*
Create the name of the service account to use
*/}}
{{- define "coolify.serviceAccountName" -}}
{{- if .Values.serviceAccount.create }}
{{- default (include "coolify.fullname" .) .Values.serviceAccount.name }}
{{- else }}
{{- default "default" .Values.serviceAccount.name }}
{{- end }}
{{- end }}

{{/*
PostgreSQL host
*/}}
{{- define "coolify.postgresql.host" -}}
{{- if .Values.postgresql.enabled }}
{{- printf "%s-postgresql" (include "coolify.fullname" .) }}
{{- else }}
{{- .Values.postgresql.external.host }}
{{- end }}
{{- end }}

{{/*
PostgreSQL port
*/}}
{{- define "coolify.postgresql.port" -}}
{{- if .Values.postgresql.enabled }}
{{- print "5432" }}
{{- else }}
{{- .Values.postgresql.external.port | default 5432 }}
{{- end }}
{{- end }}

{{/*
PostgreSQL database
*/}}
{{- define "coolify.postgresql.database" -}}
{{- if .Values.postgresql.enabled }}
{{- .Values.postgresql.auth.database | default "coolify" }}
{{- else }}
{{- .Values.postgresql.external.database | default "coolify" }}
{{- end }}
{{- end }}

{{/*
PostgreSQL username
*/}}
{{- define "coolify.postgresql.username" -}}
{{- if .Values.postgresql.enabled }}
{{- .Values.postgresql.auth.username | default "coolify" }}
{{- else }}
{{- .Values.postgresql.external.username | default "coolify" }}
{{- end }}
{{- end }}

{{/*
PostgreSQL secret name
*/}}
{{- define "coolify.postgresql.secretName" -}}
{{- if .Values.postgresql.enabled }}
{{- if .Values.postgresql.auth.existingSecret }}
{{- .Values.postgresql.auth.existingSecret }}
{{- else }}
{{- printf "%s-postgresql" (include "coolify.fullname" .) }}
{{- end }}
{{- else }}
{{- .Values.postgresql.external.existingSecret | required "postgresql.external.existingSecret is required when postgresql.enabled is false" }}
{{- end }}
{{- end }}

{{/*
PostgreSQL secret key
*/}}
{{- define "coolify.postgresql.secretKey" -}}
{{- if .Values.postgresql.enabled }}
{{- .Values.postgresql.auth.existingSecretKey | default "password" }}
{{- else }}
{{- .Values.postgresql.external.existingSecretKey | default "password" }}
{{- end }}
{{- end }}

{{/*
Redis host
*/}}
{{- define "coolify.redis.host" -}}
{{- if .Values.redis.enabled }}
{{- printf "%s-redis" (include "coolify.fullname" .) }}
{{- else }}
{{- .Values.redis.external.host }}
{{- end }}
{{- end }}

{{/*
Redis port
*/}}
{{- define "coolify.redis.port" -}}
{{- if .Values.redis.enabled }}
{{- print "6379" }}
{{- else }}
{{- .Values.redis.external.port | default 6379 }}
{{- end }}
{{- end }}

{{/*
Redis secret name
*/}}
{{- define "coolify.redis.secretName" -}}
{{- if .Values.redis.enabled }}
{{- if .Values.redis.auth.existingSecret }}
{{- .Values.redis.auth.existingSecret }}
{{- else }}
{{- printf "%s-redis" (include "coolify.fullname" .) }}
{{- end }}
{{- else }}
{{- if .Values.redis.external.existingSecret }}
{{- .Values.redis.external.existingSecret }}
{{- else }}
{{- printf "%s-redis" (include "coolify.fullname" .) }}
{{- end }}
{{- end }}
{{- end }}

{{/*
Redis secret key
*/}}
{{- define "coolify.redis.secretKey" -}}
{{- if .Values.redis.enabled }}
{{- .Values.redis.auth.existingSecretKey | default "password" }}
{{- else }}
{{- .Values.redis.external.existingSecretKey | default "password" }}
{{- end }}
{{- end }}

{{/*
App secret name
*/}}
{{- define "coolify.appSecretName" -}}
{{- if .Values.security.existingAppKeySecret }}
{{- .Values.security.existingAppKeySecret }}
{{- else }}
{{- printf "%s-app" (include "coolify.fullname" .) }}
{{- end }}
{{- end }}

{{/*
Pusher secret name
*/}}
{{- define "coolify.pusherSecretName" -}}
{{- printf "%s-pusher" (include "coolify.fullname" .) }}
{{- end }}

{{/*
Storage class
*/}}
{{- define "coolify.storageClass" -}}
{{- if .storageClass }}
{{- if (eq "-" .storageClass) }}
storageClassName: ""
{{- else }}
storageClassName: {{ .storageClass | quote }}
{{- end }}
{{- else if .global.storageClass }}
storageClassName: {{ .global.storageClass | quote }}
{{- end }}
{{- end }}

{{/*
PVC name
*/}}
{{- define "coolify.pvcName" -}}
{{- if .Values.persistence.existingClaim }}
{{- .Values.persistence.existingClaim }}
{{- else }}
{{- printf "%s-storage" (include "coolify.fullname" .) }}
{{- end }}
{{- end }}

{{/*
Ingress TLS secret name
*/}}
{{- define "coolify.ingress.tlsSecretName" -}}
{{- if .Values.ingress.tls.secretName }}
{{- .Values.ingress.tls.secretName }}
{{- else }}
{{- printf "%s-tls" (include "coolify.fullname" .) }}
{{- end }}
{{- end }}

{{/*
Soketi Ingress TLS secret name
*/}}
{{- define "coolify.soketiIngress.tlsSecretName" -}}
{{- if .Values.soketiIngress.tls.secretName }}
{{- .Values.soketiIngress.tls.secretName }}
{{- else }}
{{- printf "%s-soketi-tls" (include "coolify.fullname" .) }}
{{- end }}
{{- end }}
