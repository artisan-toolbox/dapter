// Generated from public @inertiajs/core@3.0.3 types. Run npm run native-types:generate.
/*
MIT License

Copyright (c) Jonathan Reinink <jonathan@reinink.ca>

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
*/
import type { InertiaConfig } from '@inertiajs/core';
declare module '@inertiajs/core' {
    interface InertiaConfig {}
}
type HttpRequestHeaders = Record<string, unknown>;
type HttpResponseHeaders = Record<string, string>;
interface HttpProgressEvent {
    progress: number | undefined;
    loaded: number;
    total: number | undefined;
    percentage?: number;
}
interface HttpResponse {
    status: number;
    data: string;
    headers: HttpResponseHeaders;
}
interface PageFlashData {
    [key: string]: unknown;
}
type DefaultInertiaConfig = {
    errorValueType: string;
    flashDataType: PageFlashData;
    sharedPageProps: PageProps;
    layoutProps: Record<string, unknown>;
    namedLayoutProps: Record<string, Record<string, unknown>>;
};
type InertiaConfigFor<Key extends keyof DefaultInertiaConfig> = Key extends keyof InertiaConfig
    ? InertiaConfig[Key]
    : DefaultInertiaConfig[Key];
type ErrorValue = InertiaConfigFor<'errorValueType'>;
type FlashData = InertiaConfigFor<'flashDataType'>;
type SharedPageProps = InertiaConfigFor<'sharedPageProps'>;
type Errors = Record<string, ErrorValue>;
type ErrorBag = Record<string, Errors>;
type FormDataConvertibleValue =
    | Blob
    | FormDataEntryValue
    | Date
    | boolean
    | number
    | null
    | undefined;
type FormDataConvertible =
    | Array<FormDataConvertible>
    | {
          [key: string]: FormDataConvertible;
      }
    | FormDataConvertibleValue;
type Method = 'get' | 'post' | 'put' | 'patch' | 'delete';
type RequestPayload = Record<string, FormDataConvertible> | FormData;
interface PageProps {
    [key: string]: unknown;
}
type ScrollProp = {
    pageName: string;
    previousPage: number | string | null;
    nextPage: number | string | null;
    currentPage: number | string | null;
    reset: boolean;
};
interface Page<SharedProps extends PageProps = PageProps> {
    component: string;
    props: PageProps &
        SharedProps & {
            errors: Errors & ErrorBag;
            deferred?: Record<string, VisitOptions['only']>;
        };
    url: string;
    version: string | null;
    clearHistory?: boolean;
    preserveFragment?: boolean;
    encryptHistory?: boolean;
    deferredProps?: Record<string, NonNullable<VisitOptions['only']>>;
    initialDeferredProps?: Record<string, NonNullable<VisitOptions['only']>>;
    mergeProps?: string[];
    prependProps?: string[];
    deepMergeProps?: string[];
    matchPropsOn?: string[];
    sharedProps?: string[];
    scrollProps?: Record<keyof PageProps, ScrollProp>;
    flash: FlashData;
    onceProps?: Record<
        string,
        {
            prop: keyof PageProps;
            expiresAt?: number | null;
        }
    >;
    rememberedState: Record<string, unknown>;
    optimisticUpdatedAt?: Record<string, number>;
}
type PreserveStateOption = boolean | 'errors' | ((page: Page) => boolean);
type QueryStringArrayFormatOption = 'indices' | 'brackets';
type Progress = HttpProgressEvent;
type CancelToken = {
    cancel: VoidFunction;
};
type CancelTokenCallback = (cancelToken: CancelToken) => void;
type OptimisticCallback<TProps = Page<SharedPageProps>['props']> = (
    props: TProps,
) => Partial<TProps> | void;
type Visit<T extends RequestPayload = RequestPayload> = {
    method: Method;
    data: T;
    replace: boolean;
    preserveScroll: PreserveStateOption;
    preserveState: PreserveStateOption;
    only: Array<string>;
    except: Array<string>;
    headers: Record<string, string>;
    errorBag: string | null;
    forceFormData: boolean;
    queryStringArrayFormat: QueryStringArrayFormatOption;
    async: boolean;
    showProgress: boolean;
    prefetch: boolean;
    fresh: boolean;
    reset: string[];
    preserveUrl: boolean;
    preserveErrors: boolean;
    invalidateCacheTags: string | string[];
    viewTransition: boolean | ((viewTransition: ViewTransition) => void);
    optimistic?: OptimisticCallback;
    component: string | null;
    pageProps:
        | Record<string, unknown>
        | ((currentProps: PageProps, sharedProps: Partial<PageProps>) => Record<string, unknown>)
        | null;
};
type GlobalEventsMap<T extends RequestPayload = RequestPayload> = {
    before: {
        parameters: [PendingVisit<T>];
        details: {
            visit: PendingVisit<T>;
        };
        result: boolean | void;
    };
    start: {
        parameters: [PendingVisit<T>];
        details: {
            visit: PendingVisit<T>;
        };
        result: void;
    };
    progress: {
        parameters: [Progress | undefined];
        details: {
            progress: Progress | undefined;
        };
        result: void;
    };
    finish: {
        parameters: [ActiveVisit<T>];
        details: {
            visit: ActiveVisit<T>;
        };
        result: void;
    };
    cancel: {
        parameters: [];
        details: {};
        result: void;
    };
    beforeUpdate: {
        parameters: [Page<SharedPageProps>];
        details: {
            page: Page<SharedPageProps>;
        };
        result: void;
    };
    navigate: {
        parameters: [Page<SharedPageProps>];
        details: {
            page: Page<SharedPageProps>;
        };
        result: void;
    };
    success: {
        parameters: [Page<SharedPageProps>];
        details: {
            page: Page<SharedPageProps>;
        };
        result: void;
    };
    error: {
        parameters: [Errors];
        details: {
            errors: Errors;
        };
        result: void;
    };
    httpException: {
        parameters: [HttpResponse];
        details: {
            response: HttpResponse;
        };
        result: boolean | void;
    };
    networkError: {
        parameters: [Error];
        details: {
            error: Error;
        };
        result: boolean | void;
    };
    prefetched: {
        parameters: [HttpResponse, ActiveVisit<T>];
        details: {
            response: HttpResponse;
            fetchedAt: number;
            visit: ActiveVisit<T>;
        };
        result: void;
    };
    prefetching: {
        parameters: [ActiveVisit<T>];
        details: {
            visit: ActiveVisit<T>;
        };
        result: void;
    };
    flash: {
        parameters: [Page['flash']];
        details: {
            flash: Page['flash'];
        };
        result: void;
    };
};
type GlobalEventNames<T extends RequestPayload = RequestPayload> = keyof GlobalEventsMap<T>;
type GlobalEvent<
    TEventName extends GlobalEventNames<T>,
    T extends RequestPayload = RequestPayload,
> = CustomEvent<GlobalEventDetails<TEventName, T>>;
type GlobalEventParameters<
    TEventName extends GlobalEventNames<T>,
    T extends RequestPayload = RequestPayload,
> = GlobalEventsMap<T>[TEventName]['parameters'];
type GlobalEventResult<
    TEventName extends GlobalEventNames<T>,
    T extends RequestPayload = RequestPayload,
> = GlobalEventsMap<T>[TEventName]['result'];
type GlobalEventDetails<
    TEventName extends GlobalEventNames<T>,
    T extends RequestPayload = RequestPayload,
> = GlobalEventsMap<T>[TEventName]['details'];
type GlobalEventCallback<
    TEventName extends GlobalEventNames<T>,
    T extends RequestPayload = RequestPayload,
> = (...params: GlobalEventParameters<TEventName, T>) => GlobalEventResult<TEventName, T>;
type VisitCallbacks<T extends RequestPayload = RequestPayload> = {
    onCancelToken: CancelTokenCallback;
    onBefore: GlobalEventCallback<'before', T>;
    onBeforeUpdate: GlobalEventCallback<'beforeUpdate', T>;
    onStart: GlobalEventCallback<'start', T>;
    onProgress: GlobalEventCallback<'progress', T>;
    onFinish: GlobalEventCallback<'finish', T>;
    onCancel: GlobalEventCallback<'cancel', T>;
    onSuccess: GlobalEventCallback<'success', T>;
    onError: GlobalEventCallback<'error', T>;
    onHttpException: GlobalEventCallback<'httpException', T>;
    onNetworkError: GlobalEventCallback<'networkError', T>;
    onFlash: GlobalEventCallback<'flash', T>;
    onPrefetched: GlobalEventCallback<'prefetched', T>;
    onPrefetching: GlobalEventCallback<'prefetching', T>;
};
type VisitOptions<T extends RequestPayload = RequestPayload> = Partial<
    Visit<T> & VisitCallbacks<T>
>;
type PendingVisitOptions = {
    url: URL;
    completed: boolean;
    cancelled: boolean;
    interrupted: boolean;
};
type PendingVisit<T extends RequestPayload = RequestPayload> = Visit<T> & PendingVisitOptions;
type ActiveVisit<T extends RequestPayload = RequestPayload> = PendingVisit<T> &
    Required<Omit<VisitOptions<T>, 'optimistic'>>;
type UseHttpOptions<TResponse = unknown> = {
    onBefore?: () => boolean | void;
    onStart?: () => void;
    onProgress?: (progress: HttpProgressEvent) => void;
    onSuccess?: (response: TResponse, httpResponse: HttpResponse) => void;
    onError?: (errors: Errors) => void;
    onHttpException?: (response: HttpResponse) => boolean | void;
    onNetworkError?: (error: Error) => boolean | void;
    onFinish?: () => void;
    onCancel?: () => void;
    onCancelToken?: (cancelToken: CancelToken) => void;
};
type UseHttpSubmitOptions<TResponse = unknown, TForm = unknown> = UseHttpOptions<TResponse> & {
    headers?: HttpRequestHeaders;
    optimistic?: (currentData: TForm) => Partial<TForm> | void;
};
export type { Errors, FormDataConvertible, RequestPayload, UseHttpSubmitOptions, VisitOptions };
